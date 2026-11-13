<?php

declare(strict_types=1);

namespace Deadcode\Report;

final class MarkdownReport
{
    /**
     * @param string|null $repositoryUrl web address of the repository, to link each file at the commit read
     */
    public static function render(string $app, Analysis $analysis, ?string $repositoryUrl = null): string
    {
        $blobUrl = $repositoryUrl === null || $analysis->sha === null
            ? null
            : sprintf('%s/blob/%s/%s', rtrim($repositoryUrl, '/'), $analysis->sha, self::urlPath($analysis->pathPrefix));

        return implode("\n", [
            '# Execution coverage report: ' . $app,
            '',
            sprintf('- Report day: %s (UTC)', $analysis->today),
            $analysis->firstDay === null || $analysis->lastDay === null
                ? '- Data: none yet'
                : sprintf('- Data: %s to %s', $analysis->firstDay, $analysis->lastDay),
            sprintf('- Reporting hosts: %d', $analysis->hostCount),
            '- ' . self::summary($analysis),
            '- ' . self::staticSummary($analysis),
            ...self::commits($analysis),
            '',
            ...self::warnings($analysis),
            ...self::windows($analysis),
            ...self::reporters($analysis),
            ...self::directories($analysis),
            ...($analysis->hasStaticVerdict ? self::quadrants($analysis, $blobUrl) : self::labels($analysis, $blobUrl)),
            ...self::notLabelled($analysis, $blobUrl),
            '## Limits of file-level data',
            '',
            'A file counts as alive as soon as it is loaded, even when none of its code runs: autoloading it for a type'
                . ' check, an attribute scan, a parent class or a container instantiation is enough. File-level data'
                . ' therefore under-reports dead code, and a short list does not mean there is little of it.',
            '',
        ]);
    }

    private static function staticSummary(Analysis $analysis): string
    {
        if (!$analysis->hasStaticVerdict) {
            return 'Static verdict: missing, so files are listed by runtime label only.'
                . ' Pass the detector output with `--static=<file>` to tell delete now from investigate.';
        }

        $parts = [];
        foreach (Quadrant::cases() as $quadrant) {
            $files = $analysis->filesIn($quadrant);
            $parts[] = sprintf('%d %s (%s)', count($files), str_replace('_', ' ', $quadrant->value), self::lines($files));
        }

        return 'Static verdict: ' . implode(', ', $parts);
    }

    /**
     * @return list<string>
     */
    private static function commits(Analysis $analysis): array
    {
        $lines = [];
        if ($analysis->sha !== null) {
            $lines[] = sprintf('- Commit read: `%s`', $analysis->sha);
        }
        if ($analysis->deployedShasDiffer()) {
            $lines[] = sprintf(
                '- Deployed commits: %s. Line counts, static verdicts and links come from the commit read, which is not what every node runs.',
                implode(', ', array_map(static fn (string $sha): string => '`' . $sha . '`', $analysis->deployedShas)),
            );
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function directories(Analysis $analysis): array
    {
        $directories = $analysis->directories();
        if ($directories === []) {
            return [];
        }

        $lines = [
            '## Directories with cold or dead files',
            '',
            'Each file counts in the directory that holds it directly. Only directories with at least one early cold,'
                . ' cold or dead file are listed, fully cold ones first.',
            '',
            '| Directory | Files | Cold or dead | Ratio |',
            '|---|---|---|---|',
        ];
        foreach ($directories as $tally) {
            $lines[] = sprintf(
                '| `%s` | %d | %d | %s |',
                self::cell($tally->directory),
                $tally->files,
                $tally->coldOrDead,
                $tally->fullyCold() ? '**100%**' : round(100 * $tally->coldOrDead / $tally->files) . '%',
            );
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function quadrants(Analysis $analysis, ?string $blobUrl): array
    {
        $lines = [];
        foreach (Quadrant::cases() as $quadrant) {
            $title = match ($quadrant) {
                Quadrant::DeleteNow => 'Delete now: statically unused, no hit',
                Quadrant::Investigate => 'Investigate: statically used, no hit',
                Quadrant::StaticFalsePositive => 'Static false positive: statically unused, hit',
            };
            array_push($lines, ...self::table($title, $analysis->filesIn($quadrant), $blobUrl));
        }

        $withoutVerdict = $analysis->filesWithoutStaticVerdict();
        if ($withoutVerdict !== []) {
            $table = self::table('No static verdict: no hit', $withoutVerdict, $blobUrl);
            array_splice($table, 2, 0, [
                'These files declare no method, class constant or enum case, so the detector says nothing about them:'
                    . ' functions, configuration, scripts.',
                '',
            ]);
            array_push($lines, ...$table);
        }

        return $lines;
    }

    /**
     * @param list<FileVerdict> $files
     *
     * @return list<string>
     */
    private static function table(string $title, array $files, ?string $blobUrl): array
    {
        $lines = [sprintf('## %s (%s, %s)', $title, count($files) === 1 ? '1 file' : count($files) . ' files', self::lines($files)), ''];
        if ($files === []) {
            return [...$lines, 'None.', ''];
        }

        $lines[] = '| File | Label | Last seen | Lines |';
        $lines[] = '|---|---|---|---|';
        foreach ($files as $file) {
            $lines[] = sprintf(
                '| %s | %s | %s | %d |',
                self::file($file, $blobUrl),
                $file->verdict->value,
                $file->lastSeen ?? 'never',
                $file->lines,
            );
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function labels(Analysis $analysis, ?string $blobUrl): array
    {
        $lines = [];
        foreach ($analysis->windows as $coverage) {
            if (!$coverage->judged()) {
                continue;
            }
            $files = $analysis->filesWith($coverage->window->verdict());
            $lines[] = sprintf(
                '## %s: no hit in %d days (%d)',
                ucfirst($coverage->window->verdict()->value),
                $coverage->window->value,
                count($files),
            );
            $lines[] = '';
            foreach ($files as $file) {
                $lines[] = sprintf(
                    '- %s (%s)',
                    self::file($file, $blobUrl),
                    $file->lastSeen === null ? 'never seen' : 'last seen ' . $file->lastSeen,
                );
            }
            if ($files === []) {
                $lines[] = 'None.';
            }
            $lines[] = '';
        }

        return $lines;
    }

    private static function file(FileVerdict $file, ?string $blobUrl): string
    {
        $name = '`' . self::cell($file->path) . '`';
        if ($blobUrl === null) {
            return $name;
        }

        return sprintf('[%s](%s%s)', $name, $blobUrl, self::urlPath($file->path));
    }

    private static function urlPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function cell(string $text): string
    {
        return str_replace('|', '\|', $text);
    }

    /**
     * @param list<FileVerdict> $files
     */
    private static function lines(array $files): string
    {
        $lines = array_sum(array_column($files, 'lines'));

        return $lines === 1 ? '1 line' : $lines . ' lines';
    }

    private static function summary(Analysis $analysis): string
    {
        $parts = [];
        foreach ([Verdict::Alive, Verdict::Dead, Verdict::Cold, Verdict::EarlyCold] as $verdict) {
            $parts[] = sprintf('%d %s', count($analysis->filesWith($verdict)), $verdict->value);
        }
        $parts[] = sprintf(
            '%d not labelled',
            count($analysis->filesWith(Verdict::TooYoung))
                + count($analysis->filesWith(Verdict::NoHistory))
                + count($analysis->filesWith(Verdict::NotJudged)),
        );

        return sprintf('Tracked PHP files: %d (%s)', count($analysis->files), implode(', ', $parts));
    }

    /**
     * @return list<string>
     */
    private static function warnings(Analysis $analysis): array
    {
        $lines = [];
        if (!$analysis->historyComplete) {
            $lines[] = '> **The git history is shallow.** The day each file was added cannot be read, so no label is issued.'
                . ' Run the report on a full clone (`fetch-depth: 0` with `actions/checkout`).';
            $lines[] = '';
        }
        foreach ($analysis->warnings as $warning) {
            $lines[] = '> **Warning:** ' . $warning;
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function windows(Analysis $analysis): array
    {
        $lines = [
            '## Observation windows',
            '',
            '| Label | No hit since | Judged |',
            '|---|---|---|',
        ];
        $notJudged = [];
        foreach ($analysis->windows as $coverage) {
            $label = $coverage->window->verdict()->value;
            $lines[] = sprintf(
                '| %s | %s (%d days) | %s |',
                $label,
                $coverage->start,
                $coverage->window->value,
                $coverage->judged() ? 'yes' : 'no',
            );
            if (!$coverage->judged()) {
                $notJudged[] = sprintf('- The %s label is not issued: %s.', $label, implode('; ', $coverage->gaps));
            }
        }
        $lines[] = '';

        if ($notJudged !== []) {
            $lines[] = 'Not enough data yet:';
            $lines[] = '';
            array_push($lines, ...$notJudged);
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function reporters(Analysis $analysis): array
    {
        $lines = ['## Reporters', ''];
        if ($analysis->reporters === []) {
            return [...$lines, 'None.', ''];
        }

        $lines[] = '| Host | Source | First seen | Last seen | Status |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($analysis->reporters as $reporter) {
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s |',
                str_replace('|', '\|', $reporter->host),
                $reporter->source,
                $reporter->firstSeen,
                $reporter->lastSeen,
                $reporter->silent() ? sprintf('**silent for %d days**', $reporter->daysSilent) : 'active',
            );
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function notLabelled(Analysis $analysis, ?string $blobUrl): array
    {
        $tooYoung = $analysis->filesWith(Verdict::TooYoung);
        $noHistory = $analysis->filesWith(Verdict::NoHistory);
        $notJudged = $analysis->filesWith(Verdict::NotJudged);
        if ($tooYoung === [] && $noHistory === [] && $notJudged === []) {
            return [];
        }

        $lines = ['## Not labelled', ''];
        if ($notJudged !== []) {
            $lines[] = sprintf(
                '%d %s no hit in a window that is not judged yet.',
                count($notJudged),
                count($notJudged) === 1 ? 'file has' : 'files have',
            );
            $lines[] = '';
        }
        if ($noHistory !== [] && !$analysis->historyComplete) {
            $lines[] = sprintf(
                '%d %s no recent hit and an unknown age, because the git history is shallow.',
                count($noHistory),
                count($noHistory) === 1 ? 'file has' : 'files have',
            );
            $lines[] = '';
        }
        if ($noHistory !== [] && $analysis->historyComplete) {
            $lines[] = 'No commit adds these files, so their age is unknown:';
            $lines[] = '';
            foreach ($noHistory as $file) {
                $lines[] = '- ' . self::file($file, $blobUrl);
            }
            $lines[] = '';
        }
        if ($tooYoung !== []) {
            $lines[] = 'Added to git too recently to be judged:';
            $lines[] = '';
            foreach ($tooYoung as $file) {
                $lines[] = sprintf('- %s (added %s)', self::file($file, $blobUrl), (string) $file->addedOn);
            }
            $lines[] = '';
        }

        return $lines;
    }
}
