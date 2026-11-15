<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * The machine-readable counterpart of the Markdown report, for tracking totals from one report to the next.
 */
final class JsonReport
{
    public const VERSION = 1;

    public static function render(string $app, Analysis $analysis): string
    {
        $windows = [];
        $labels = [];
        foreach ($analysis->windows as $coverage) {
            $verdict = $coverage->window->verdict();
            $windows[] = [
                'label' => $verdict->value,
                'days' => $coverage->window->value,
                'start' => $coverage->start,
                'judged' => $coverage->judged(),
            ];
            $labels[str_replace(' ', '_', $verdict->value)] = self::tally($analysis->filesWith($verdict));
        }

        $quadrants = null;
        if ($analysis->hasStaticVerdict) {
            foreach (Quadrant::cases() as $quadrant) {
                $quadrants[$quadrant->value] = self::tally($analysis->filesIn($quadrant));
            }
        }

        $files = [];
        foreach ($analysis->files as $file) {
            $files[] = [
                'path' => $file->path,
                'label' => $file->verdict->value,
                'static' => $file->static?->value,
                'quadrant' => $file->quadrant()?->value,
                'last_seen' => $file->lastSeen,
                'added_on' => $file->addedOn,
                'lines' => $file->lines,
                'allowlisted' => $file->allowlisted === null
                    ? null
                    : ['reason' => $file->allowlisted->reason, 'expires' => $file->allowlisted->expires],
            ];
        }

        $allowlist = [];
        foreach ($analysis->allowlist as $status) {
            $allowlist[] = [
                'paths' => $status->entry->paths->patterns,
                'reason' => $status->entry->reason,
                'expires' => $status->entry->expires,
                'expired' => $status->expired,
                'files' => $status->files,
                'cold_or_dead' => $status->coldOrDead,
            ];
        }

        $packages = null;
        if ($analysis->packages !== null) {
            $packages = [
                'installed' => $analysis->packages->installed,
                'dev_only' => $analysis->packages->devOnly,
                'not_loadable' => $analysis->packages->notLoadable,
                'judged' => $analysis->packages->judged(),
                'unused' => array_map(
                    static fn (Package $package): array => [
                        'name' => $package->name,
                        'version' => $package->version,
                        'path' => $package->path,
                        'direct' => $package->direct,
                    ],
                    $analysis->packages->unused,
                ),
            ];
        }

        return json_encode(
            [
                'v' => self::VERSION,
                'app' => $app,
                'today' => $analysis->today,
                'sha' => $analysis->sha,
                'config' => [
                    'file' => $analysis->config->file,
                    'include' => $analysis->config->include->patterns,
                    'exclude' => $analysis->config->exclude->patterns,
                    'left_out' => $analysis->leftOut,
                ],
                'windows' => $windows,
                'labels' => $labels,
                'quadrants' => $quadrants,
                'allowlisted' => self::tally($analysis->filesKept()),
                'allowlist' => $allowlist,
                'files' => $files,
                'packages' => $packages,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * @param list<FileVerdict> $files
     *
     * @return array{files: int, lines: int}
     */
    private static function tally(array $files): array
    {
        return ['files' => count($files), 'lines' => array_sum(array_column($files, 'lines'))];
    }
}
