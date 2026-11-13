<?php

declare(strict_types=1);

namespace Deadcode\Report;

final class MarkdownReport
{
    /**
     * @param list<string>                                       $trackedFiles project-relative paths
     * @param array<string, array{hits: int, last_seen: string}> $export       collector export by path
     */
    public static function render(string $app, array $trackedFiles, array $export): string
    {
        $zeroHits = array_values(array_filter(
            $trackedFiles,
            static fn (string $path): bool => ($export[$path]['hits'] ?? 0) === 0,
        ));
        sort($zeroHits);

        $lines = [
            '# Execution coverage report: ' . $app,
            '',
            sprintf(
                '%d tracked PHP %s, %d with zero hits.',
                count($trackedFiles),
                count($trackedFiles) === 1 ? 'file' : 'files',
                count($zeroHits),
            ),
            '',
        ];

        if ($zeroHits === []) {
            $lines[] = 'Every tracked PHP file has at least one hit.';
        } else {
            $lines[] = '## Files with zero hits';
            $lines[] = '';
            foreach ($zeroHits as $path) {
                $lines[] = sprintf('- `%s`', $path);
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
