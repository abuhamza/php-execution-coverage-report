<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * Everything the report states, computed from the tracked files, the collector export and a fixed day.
 */
final class Analysis
{
    /**
     * A reporter is silent once its last batch is more than this many days old. A stretch of more than this
     * many days without a batch of a source is a gap in its collection; a shorter one is listed only.
     */
    public const SILENT_AFTER_DAYS = 2;

    /**
     * The only source whose coverage decides whether a label is issued: a server cron drives it every few
     * minutes, so a gap there is a fault. `cli` and `worker` report when there is work, so theirs only warn.
     */
    public const GATING_SOURCE = 'fpm';

    /**
     * @param string|null                $firstDay        earliest day with an accepted batch
     * @param string|null                $lastDay         latest day with an accepted batch
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
     * @param PackageUsage|null          $packages        null when the installed packages were not given
     * @param array<string, list<array{string, string}>>|null $missingDays by source, the stretches without a batch
     *                                                    since the start of the longest window, as first and last
     *                                                    missing day; null when the collector does not record batch
     *                                                    days and continuity is judged from reporter lifetimes
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
        public readonly ?PackageUsage $packages,
        public readonly ?array $missingDays,
    ) {
    }

    public static function of(
        TrackedFiles $tracked,
        Export $export,
        string $today,
        ?StaticFindings $static = null,
        ?Config $config = null,
        ?InstalledPackages $installed = null,
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

        $longestStart = min(array_map(static fn (Window $window): string => $window->startsOn($today), Window::cases()));
        $missingDays = null;
        $firstDayBySource = array_map(static fn (array $lifetimes): string => $lifetimes[0][0], $lifetimesBySource);
        if ($export->batchDays !== null) {
            $missingDays = [];
            foreach ($export->batchDays as $source => $days) {
                $missingDays[$source] = self::stretchesSince($days['missing'], $longestStart);
                $firstDayBySource[$source] = $days['first_day'];
                $lastSeenBySource[$source] = max($lastSeenBySource[$source] ?? $days['last_day'], $days['last_day']);
            }
        }
        ksort($firstDayBySource);
        // A source counts once it sent a batch, whether or not a reporter record of it is still held.
        $hasGating = isset($firstDayBySource[self::GATING_SOURCE]);

        $windows = [];
        $collectionGaps = [];
        foreach (Window::cases() as $window) {
            $start = $window->startsOn($today);
            $gaps = [];
            if ($export->firstDay === null) {
                $gaps[] = 'the collector holds no data for this app';
            } elseif ($export->firstDay > $start) {
                $gaps[] = sprintf('data starts on %s, after the window start', $export->firstDay);
            }
            if ($hasGating) {
                $firstDay = $firstDayBySource[self::GATING_SOURCE];
                if ($firstDay > $start) {
                    $gaps[] = sprintf('`%s` reporting started on %s, after the window start', self::GATING_SOURCE, $firstDay);
                } elseif ($missingDays === null && $gating !== null) {
                    $hole = self::hole($gating, $start);
                    if ($hole !== null) {
                        $gaps[] = sprintf('no `%s` reporter was seen between %s and %s', self::GATING_SOURCE, ...$hole);
                    }
                } else {
                    $long = self::longStretches(self::stretchesSince($missingDays[self::GATING_SOURCE] ?? [], $start));
                    if ($long !== []) {
                        $gaps[] = sprintf('`%s` sent no batch on %s', self::GATING_SOURCE, self::listStretches($long));
                    }
                }
                if ($lastSeenBySource[self::GATING_SOURCE] < $activeSince) {
                    $gaps[] = sprintf('`%s` has not reported since %s', self::GATING_SOURCE, $lastSeenBySource[self::GATING_SOURCE]);
                }
            }
            // Package usage needs no file age, and the last window is the shortest one.
            $collectionGaps = $gaps;
            if (!$tracked->historyComplete) {
                array_unshift($gaps, 'the git history is shallow, so file ages are unknown');
            }
            $windows[] = new WindowCoverage($window, $start, $gaps);
        }

        $warnings = [];
        if ($missingDays === null && $export->reporters !== []) {
            $warnings[] = sprintf(
                'The collector does not record the days each source sent a batch (an older version): `%s` continuity'
                    . ' is judged from the first and last day of each reporter only, so a gap inside a reporter\'s'
                    . ' lifetime goes unnoticed.',
                self::GATING_SOURCE,
            );
        }
        if (!$hasGating) {
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
        if ($hasGating && $missingDays !== null) {
            $short = array_filter(
                $missingDays[self::GATING_SOURCE] ?? [],
                static fn (array $stretch): bool => self::length($stretch) <= self::SILENT_AFTER_DAYS,
            );
            if ($short !== []) {
                $warnings[] = sprintf(
                    '`%s` sent no batch on %s: up to %d days in a row do not block a label, but files that only'
                        . ' ran on those days may be listed as cold or dead wrongly.',
                    self::GATING_SOURCE,
                    self::listStretches(array_values($short)),
                    self::SILENT_AFTER_DAYS,
                );
            }
        }
        foreach ($firstDayBySource as $source => $firstDay) {
            if ($source !== self::GATING_SOURCE) {
                array_push($warnings, ...self::sourceWarnings(
                    (string) $source,
                    $lifetimesBySource[$source] ?? [],
                    $firstDay,
                    $lastSeenBySource[$source],
                    $missingDays === null ? null : ($missingDays[$source] ?? []),
                    $windows,
                    $activeSince,
                ));
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
            $installed === null ? null : self::packageUsage($installed, $export, $collectionGaps),
            $missingDays,
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
     * @param list<string> $gaps why collection does not cover the shortest window, empty when it does
     */
    private static function packageUsage(InstalledPackages $installed, Export $export, array $gaps): PackageUsage
    {
        $devOnly = 0;
        $notLoadable = 0;
        $unused = [];
        foreach ($installed->packages as $package) {
            if ($package->dev) {
                $devOnly++;
            } elseif (!$package->loadable()) {
                $notLoadable++;
            } elseif ($gaps === [] && !self::anyUnder($export->files, $package->path . '/')) {
                $unused[] = $package;
            }
        }

        return new PackageUsage(count($installed->packages), $devOnly, $notLoadable, $gaps, $unused);
    }

    /**
     * @param array<string, mixed> $files by project-relative path
     */
    private static function anyUnder(array $files, string $directory): bool
    {
        foreach ($files as $path => $_) {
            if (str_starts_with($path, $directory)) {
                return true;
            }
        }

        return false;
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
     * @param list<array{string, string}> $stretches first and last missing day, sorted
     *
     * @return list<array{string, string}> the part of each stretch on or after $start
     */
    private static function stretchesSince(array $stretches, string $start): array
    {
        $since = [];
        foreach ($stretches as [$from, $to]) {
            if ($to >= $start) {
                $since[] = [max($from, $start), $to];
            }
        }

        return $since;
    }

    /**
     * @param list<array{string, string}> $stretches
     *
     * @return list<array{string, string}> the stretches of more than SILENT_AFTER_DAYS days
     */
    private static function longStretches(array $stretches): array
    {
        return array_values(array_filter(
            $stretches,
            static fn (array $stretch): bool => self::length($stretch) > self::SILENT_AFTER_DAYS,
        ));
    }

    /**
     * @param array{string, string} $stretch
     */
    private static function length(array $stretch): int
    {
        return Day::between($stretch[0], $stretch[1]) + 1;
    }

    /**
     * @param non-empty-list<array{string, string}> $stretches
     */
    private static function listStretches(array $stretches): string
    {
        return implode(', ', array_map(
            static fn (array $stretch): string => $stretch[0] === $stretch[1]
                ? $stretch[0]
                : sprintf('%s to %s (%d days)', $stretch[0], $stretch[1], self::length($stretch)),
            $stretches,
        ));
    }

    /**
     * @param list<array{string, string}>           $lifetimes first-seen and last-seen day of each reporter, sorted
     * @param string                                $firstDay  first day the source sent a batch
     * @param string                                $lastSeen  last day the source sent a batch
     * @param list<array{string, string}>|null      $missing   stretches without a batch since the longest window
     *                                                         start; null to judge from $lifetimes instead
     * @param list<WindowCoverage>                  $windows
     *
     * @return list<string>
     */
    private static function sourceWarnings(
        string $source,
        array $lifetimes,
        string $firstDay,
        string $lastSeen,
        ?array $missing,
        array $windows,
        string $activeSince,
    ): array {
        $risk = match ($source) {
            'cli' => 'files that only run from the command line',
            'worker' => 'files that only run in workers',
            default => sprintf('files that only run under `%s`', $source),
        };

        $warnings = [];
        if ($lastSeen < $activeSince) {
            $warnings[] = sprintf('`%s` has not reported since %s: %s may be listed as cold or dead wrongly.', $source, $lastSeen, $risk);
        }

        $startedInside = [];
        $holes = [];
        foreach ($windows as $coverage) {
            if ($firstDay > $coverage->start) {
                $startedInside[] = $coverage->window->verdict()->value;
            } elseif ($missing === null && $lifetimes !== [] && ($hole = self::hole($lifetimes, $coverage->start)) !== null) {
                $holes[implode(' ', $hole)] = $hole;
            }
        }
        if ($startedInside !== []) {
            $last = array_pop($startedInside);
            $warnings[] = sprintf(
                '`%s` reporting started on %s, after the start of the %s %s: %s may be listed wrongly.',
                $source,
                $firstDay,
                $startedInside === [] ? $last : implode(', ', $startedInside) . ' and ' . $last,
                $startedInside === [] ? 'window' : 'windows',
                $risk,
            );
        }
        foreach ($holes as [$from, $to]) {
            $warnings[] = sprintf('No `%s` reporter was seen between %s and %s: %s may be listed wrongly.', $source, $from, $to, $risk);
        }
        $long = self::longStretches($missing ?? []);
        if ($long !== []) {
            $warnings[] = sprintf('`%s` sent no batch on %s: %s may be listed wrongly.', $source, self::listStretches($long), $risk);
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
