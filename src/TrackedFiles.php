<?php

declare(strict_types=1);

namespace Deadcode\Report;

use RuntimeException;

final class TrackedFiles
{
    /**
     * @return list<string> PHP files tracked by git, relative to the project directory
     */
    public static function php(string $projectDir): array
    {
        $process = @proc_open(
            ['git', 'ls-files', '-z', '--', '*.php'],
            [1 => ['pipe', 'w']],
            $pipes,
            $projectDir,
        );
        if ($process === false) {
            throw new RuntimeException(sprintf('Could not run git in "%s".', $projectDir));
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(sprintf('Could not list the tracked files of "%s".', $projectDir));
        }

        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    }
}
