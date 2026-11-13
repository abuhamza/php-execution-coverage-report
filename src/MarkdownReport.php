<?php

declare(strict_types=1);

namespace Deadcode\Report;

final class MarkdownReport
{
    public static function render(string $app, Analysis $analysis): string
    {
        $lines = [
            '# Execution coverage report: ' . $app,
            '',
            sprintf('- Report day: %s (UTC)', $analysis->today),
            $analysis->firstDay === null || $analysis->lastDay === null
                ? '- Data: none yet'
                : sprintf('- Data: %s to %s', $analysis->firstDay, $analysis->lastDay),
            sprintf('- Reporting hosts: %d', $analysis->hostCount),
            '- ' . self::summary($analysis),
            '',
            ...self::warnings($analysis),
            ...self::windows($analysis),
            ...self::reporters($analysis),
        ];

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
                    '- `%s` (%s)',
                    $file->path,
                    $file->lastSeen === null ? 'never seen' : 'last seen ' . $file->lastSeen,
                );
            }
            if ($files === []) {
                $lines[] = 'None.';
            }
            $lines[] = '';
        }

        return implode("\n", [...$lines, ...self::notLabelled($analysis)]);
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
    private static function notLabelled(Analysis $analysis): array
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
                $lines[] = sprintf('- `%s`', $file->path);
            }
            $lines[] = '';
        }
        if ($tooYoung !== []) {
            $lines[] = 'Added to git too recently to be judged:';
            $lines[] = '';
            foreach ($tooYoung as $file) {
                $lines[] = sprintf('- `%s` (added %s)', $file->path, (string) $file->addedOn);
            }
            $lines[] = '';
        }

        return $lines;
    }
}
