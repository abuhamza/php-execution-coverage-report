<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * Everything the report states, computed from the tracked files, the collector export and a fixed day.
 */
final class Analysis
{
    /**
     * A reporter is silent once its last batch is more than this many days old. Two lifetimes of reporters
     * of the same source separated by more than this many days leave a gap in collection.
     */
    public const SILENT_AFTER_DAYS = 2;

    /**
     * The only source whose coverage decides whether a label is issued: a server cron drives it every few
     * minutes, so a gap there is a fault. `cli` and `worker` report when there is work, so theirs only warn.
     */
    public const GATING_SOURCE = 'fpm';

    /**
     * @param string|null                $firstDay        earliest day with a stored hit
     * @param string|null                $lastDay         latest day with a stored hit
     * @param bool                       $historyComplete false when git history is shallow and file ages are unknown
     * @param list<ReporterStatus>       $reporters       sorted as in the export: by host, then source
     * @param list<WindowCoverage>       $windows         strongest label first
     * @param list<string>               $warnings        what may make the labels wrong although they are issued,
     *                                                    or why none is: one sentence each
     * @param array<string, FileVerdict> $files           every first-party file by path, sorted by path
     * @param bool                       $hasStaticVerdict false when no static findings were given, so no file has a quadrant
     * @param string|null                $sha             the commit line counts and static verdicts were read at
     * @param string                     $pathPrefix      path of the project directory inside its repository
     * @param list<string>               $deployedShas    the distinct commits the reporters last ran, sorted
     * @param int                        $leftOut         tracked PHP files the config does not count as first-party
     * @param list<AllowlistStatus>      $allowlist       one per entry of the config, in its order
     */
    private function __construct(
        public readonly string $today,
        public readonly ?string $firstDay,
        public readonly ?string $lastDay,
        public readonly int $hostCount,
        public readonly bool $historyComplete,
        public readonly array $reporters,
        public readonly array $windows,
        public readonly array $warnings,
        public readonly array $files,
        public readonly bool $hasStaticVerdict,
        public readonly ?string $sha,
        public readonly string $pathPrefix,
        public readonly array $deployedShas,
        public readonly Config $config,
        public readonly int $leftOut,
        public readonly array $allowlist,
    ) {
    }

    public static function of(
        TrackedFiles $tracked,
        Export $export,
        string $today,
        ?StaticFindings $static = null,
        ?Config $config = null,
    ): self {
        $config ??= Config::default();
        $activeSince = Day::minus($today, self::SILENT_AFTER_DAYS);

        $reporters = [];
        $lifetimesBySource = [];
        foreach ($export->reporters as $reporter) {
            $reporters[] = new ReporterStatus(
                $reporter['host'],
                $reporter['source'],
                $reporter['first_seen'],
                $reporter['last_seen'],
                max(0, Day::between($reporter['last_seen'], $today)),
            );
            $lifetimesBySource[$reporter['source']][] = [$reporter['first_seen'], $reporter['last_seen']];
        }
        ksort($lifetimesBySource);

        $lastSeenBySource = [];
        foreach ($lifetimesBySource as $source => $lifetimes) {
            sort($lifetimes);
            $lifetimesBySource[$source] = $lifetimes;
            $lastSeenBySource[$source] = max(array_column($lifetimes, 1));
        }
        $gating = $lifetimesBySource[self::GATING_SOURCE] ?? null;

        $windows = [];
        foreach (Window::cases() as $window) {
            $start = $window->startsOn($today);
            $gaps = [];
            if (!$tracked->historyComplete) {
                $gaps[] = 'the git history is shallow, so file ages are unknown';
            }
            if ($export->firstDay === null) {
                $gaps[] = 'the collector holds no data for this app';
            } elseif ($export->firstDay > $start) {
                $gaps[] = sprintf('data starts on %s, after the window start', $export->firstDay);
            }
            if ($gating !== null) {
                $hole = self::hole($gating, $start);
                if ($gating[0][0] > $start) {
                    $gaps[] = sprintf('`%s` reporting started on %s, after the window start', self::GATING_SOURCE, $gating[0][0]);
                } elseif ($hole !== null) {
                    $gaps[] = sprintf('no `%s` reporter was seen between %s and %s', self::GATING_SOURCE, ...$hole);
                }
                if ($lastSeenBySource[self::GATING_SOURCE] < $activeSince) {
                    $gaps[] = sprintf('`%s` has not reported since %s', self::GATING_SOURCE, $lastSeenBySource[self::GATING_SOURCE]);
                }
            }
            $windows[] = new WindowCoverage($window, $start, $gaps);
        }

        $warnings = [];
        if ($gating === null) {
            $warnings[] = sprintf(
                'No `%s` reporter has ever reported: web requests are not observed, so files that only run there'
                    . ' may be listed as cold or dead wrongly.',
                self::GATING_SOURCE,
            );
        } elseif ($lastSeenBySource[self::GATING_SOURCE] < $activeSince) {
            $warnings[] = sprintf(
                '`%s` has not reported since %s: web requests are no longer observed, so no label is issued until it reports again.',
                self::GATING_SOURCE,
                $lastSeenBySource[self::GATING_SOURCE],
            );
        }
        foreach ($lifetimesBySource as $source => $lifetimes) {
            if ($source !== self::GATING_SOURCE) {
                array_push($warnings, ...self::sourceWarnings((string) $source, $lifetimes, $windows, $activeSince));
            }
        }

        $live = array_filter($config->allowlist, static fn (AllowlistEntry $entry): bool => !$entry->expired($today));
        $files = [];
        foreach ($tracked->addedOn as $path => $addedOn) {
            $seen = $export->files[$path] ?? null;
            $lastSeen = $seen['last_seen'] ?? null;
            $source = $tracked->sources[$path] ?? new SourceFile(0, []);
            $files[$path] = new FileVerdict(
                $path,
                self::verdict($windows, $lastSeen, $addedOn),
                $seen['hits'] ?? 0,
                $lastSeen,
                $addedOn,
                $source->lines,
                $static?->verdict($source),
                self::firstNaming($live, $path),
            );
        }
        ksort($files, SORT_STRING);

        $allowlist = [];
        foreach ($config->allowlist as $entry) {
            $named = array_filter($files, static fn (FileVerdict $file): bool => $entry->paths->match($file->path));
            $allowlist[] = new AllowlistStatus(
                $entry,
                $entry->expired($today),
                count($named),
                array_column(array_filter($named, static fn (FileVerdict $file): bool => $file->verdict->coldOrDead()), 'path'),
            );
        }

        $deployedShas = array_values(array_unique(array_filter(array_column($export->reporters, 'sha'))));
        sort($deployedShas);

        return new self(
            $today,
            $export->firstDay,
            $export->lastDay,
            count(array_unique(array_column($export->reporters, 'host'))),
            $tracked->historyComplete,
            $reporters,
            $windows,
            $warnings,
            $files,
            $static !== null,
            $tracked->sha,
            $tracked->pathPrefix,
            $deployedShas,
            $config,
            $tracked->leftOut,
            $allowlist,
        );
    }

    /**
     * @return list<FileVerdict> the early cold, cold and dead files a live allowlist entry keeps out of the
     *                           candidate sections, strongest label first, then by path
     */
    public function filesKept(): array
    {
        return self::strongestFirst(array_filter($this->files, static fn (FileVerdict $file): bool => $file->kept()));
    }

    /**
     * @return list<FileVerdict> strongest label first, then by path
     */
    public function filesIn(Quadrant $quadrant): array
    {
        return self::strongestFirst(array_filter(
            $this->files,
            static fn (FileVerdict $file): bool => $file->quadrant() === $quadrant,
        ));
    }

    /**
     * @return list<FileVerdict> the early cold, cold and dead files the detector has nothing to say about,
     *                           strongest label first, then by path; empty without static findings
     */
    public function filesWithoutStaticVerdict(): array
    {
        return self::strongestFirst(array_filter(
            $this->files,
            static fn (FileVerdict $file): bool => $file->static === StaticVerdict::None && $file->candidate(),
        ));
    }

    /**
     * The directories that hold at least one early cold, cold or dead file directly, fully cold ones first,
     * then by falling share of such files, by falling number of them and by path. A file kept by the
     * allowlist counts as a file of its directory, not as a cold or dead one.
     *
     * @return list<DirectoryTally>
     */
    public function directories(): array
    {
        $files = [];
        $coldOrDead = [];
        foreach ($this->files as $file) {
            $directory = dirname($file->path);
            $files[$directory] = ($files[$directory] ?? 0) + 1;
            $coldOrDead[$directory] = ($coldOrDead[$directory] ?? 0) + (int) $file->candidate();
        }

        $tallies = [];
        foreach ($files as $directory => $count) {
            if ($coldOrDead[$directory] > 0) {
                $tallies[] = new DirectoryTally((string) $directory, $count, $coldOrDead[$directory]);
            }
        }
        usort($tallies, static fn (DirectoryTally $a, DirectoryTally $b): int => [
            $b->coldOrDead * $a->files,
            $b->coldOrDead,
            $a->directory,
        ] <=> [
            $a->coldOrDead * $b->files,
            $a->coldOrDead,
            $b->directory,
        ]);

        return $tallies;
    }

    /**
     * True when production runs a commit other than the one this report read, or more than one commit.
     * An abbreviated commit matches the full one it starts.
     */
    public function deployedShasDiffer(): bool
    {
        if (count($this->deployedShas) > 1) {
            return true;
        }
        $deployed = $this->deployedShas[0] ?? null;

        return $deployed !== null
            && $this->sha !== null
            && !str_starts_with($this->sha, $deployed)
            && !str_starts_with($deployed, $this->sha);
    }

    /**
     * @return list<FileVerdict> sorted by path, without the files the allowlist keeps
     */
    public function filesWith(Verdict $verdict): array
    {
        return array_values(array_filter(
            $this->files,
            static fn (FileVerdict $file): bool => $file->verdict === $verdict && !$file->kept(),
        ));
    }

    /**
     * @param array<AllowlistEntry> $entries
     */
    private static function firstNaming(array $entries, string $path): ?AllowlistEntry
    {
        foreach ($entries as $entry) {
            if ($entry->paths->match($path)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, FileVerdict> $files sorted by path
     *
     * @return list<FileVerdict>
     */
    private static function strongestFirst(array $files): array
    {
        $files = array_values($files);
        $rank = [Verdict::Dead->value => 0, Verdict::Cold->value => 1, Verdict::EarlyCold->value => 2];
        usort($files, static fn (FileVerdict $a, FileVerdict $b): int => [
            $rank[$a->verdict->value] ?? 3,
            $a->path,
        ] <=> [
            $rank[$b->verdict->value] ?? 3,
            $b->path,
        ]);

        return $files;
    }

    /**
     * The first stretch of more than SILENT_AFTER_DAYS days, on or after $start, between the lifetimes of
     * reporters of one source that were already reporting on $start. Null when there is none.
     *
     * @param non-empty-list<array{string, string}> $lifetimes first-seen and last-seen day of each reporter, sorted
     *
     * @return array{string, string}|null last day seen before the stretch and first day seen after it
     */
    private static function hole(array $lifetimes, string $start): ?array
    {
        $coveredUntil = $lifetimes[0][1];
        foreach ($lifetimes as [$firstSeen, $lastSeen]) {
            if ($firstSeen > max($start, Day::plus($coveredUntil, self::SILENT_AFTER_DAYS))) {
                return [$coveredUntil, $firstSeen];
            }
            $coveredUntil = max($coveredUntil, $lastSeen);
        }

        return null;
    }

    /**
     * @param non-empty-list<array{string, string}> $lifetimes first-seen and last-seen day of each reporter, sorted
     * @param list<WindowCoverage>                  $windows
     *
     * @return list<string>
     */
    private static function sourceWarnings(string $source, array $lifetimes, array $windows, string $activeSince): array
    {
        $risk = match ($source) {
            'cli' => 'files that only run from the command line',
            'worker' => 'files that only run in workers',
            default => sprintf('files that only run under `%s`', $source),
        };

        $warnings = [];
        $lastSeen = max(array_column($lifetimes, 1));
        if ($lastSeen < $activeSince) {
            $warnings[] = sprintf('`%s` has not reported since %s: %s may be listed as cold or dead wrongly.', $source, $lastSeen, $risk);
        }

        $startedInside = [];
        $holes = [];
        foreach ($windows as $coverage) {
            if ($lifetimes[0][0] > $coverage->start) {
                $startedInside[] = $coverage->window->verdict()->value;
            } elseif (($hole = self::hole($lifetimes, $coverage->start)) !== null) {
                $holes[implode(' ', $hole)] = $hole;
            }
        }
        if ($startedInside !== []) {
            $last = array_pop($startedInside);
            $warnings[] = sprintf(
                '`%s` reporting started on %s, after the start of the %s %s: %s may be listed wrongly.',
                $source,
                $lifetimes[0][0],
                $startedInside === [] ? $last : implode(', ', $startedInside) . ' and ' . $last,
                $startedInside === [] ? 'window' : 'windows',
                $risk,
            );
        }
        foreach ($holes as [$from, $to]) {
            $warnings[] = sprintf('No `%s` reporter was seen between %s and %s: %s may be listed wrongly.', $source, $from, $to, $risk);
        }

        return $warnings;
    }

    /**
     * @param list<WindowCoverage> $windows strongest label first
     */
    private static function verdict(array $windows, ?string $lastSeen, ?string $addedOn): Verdict
    {
        $oldEnoughForAnUnjudgedWindow = false;
        $hitInEveryWindow = true;
        foreach ($windows as $coverage) {
            if ($lastSeen !== null && $lastSeen >= $coverage->start) {
                continue;
            }
            $hitInEveryWindow = false;
            if ($addedOn === null || $addedOn > $coverage->start) {
                continue;
            }
            if ($coverage->judged()) {
                return $coverage->window->verdict();
            }
            $oldEnoughForAnUnjudgedWindow = true;
        }

        return match (true) {
            $hitInEveryWindow => Verdict::Alive,
            $addedOn === null => Verdict::NoHistory,
            $oldEnoughForAnUnjudgedWindow => Verdict::NotJudged,
            default => Verdict::TooYoung,
        };
    }
}
