<?php

declare(strict_types=1);

namespace Deadcode\Report;

use RuntimeException;

/**
 * The first-party PHP files git tracks in a project, the day each one entered its history and what the working
 * tree holds of them. This is the set of files every part of the report works on.
 */
final class TrackedFiles
{
    /**
     * @param array<string, string|null> $addedOn         by project-relative path, the UTC day of the latest commit
     *                                                    that added the file; null when history does not say
     * @param bool                       $historyComplete false for a shallow clone, where no add day can be trusted
     * @param array<string, SourceFile>  $sources         by project-relative path, for the files present in the working tree
     * @param string|null                $sha             the checked-out commit, null when unknown
     * @param string                     $pathPrefix      path of the project directory inside its repository: empty,
     *                                                    or ending with a slash
     * @param int                        $leftOut         tracked PHP files that are not first-party
     */
    public function __construct(
        public readonly array $addedOn,
        public readonly bool $historyComplete = true,
        public readonly array $sources = [],
        public readonly ?string $sha = null,
        public readonly string $pathPrefix = '',
        public readonly int $leftOut = 0,
    ) {
    }

    public static function php(string $projectDir, Config $config): self
    {
        $tracked = array_filter(
            explode("\0", self::git($projectDir, ['ls-files', '-z', '--', '*.php'])),
            static fn (string $path): bool => $path !== '',
        );
        $paths = array_values(array_filter($tracked, $config->firstParty(...)));
        $leftOut = count($tracked) - count($paths);

        $sources = [];
        foreach ($paths as $path) {
            $code = @file_get_contents($projectDir . '/' . $path);
            if ($code !== false) {
                $sources[$path] = SourceFile::parse($code);
            }
        }
        $sha = trim(self::git($projectDir, ['rev-parse', 'HEAD']));
        $pathPrefix = trim(self::git($projectDir, ['rev-parse', '--show-prefix']));

        if (trim(self::git($projectDir, ['rev-parse', '--is-shallow-repository'])) === 'true') {
            return new self(array_fill_keys($paths, null), false, $sources, $sha, $pathPrefix, $leftOut);
        }

        // A rename counts as the addition of the new path: without --no-renames it would not be listed at all.
        $added = self::parseAdditions(self::git(
            $projectDir,
            ['log', '--diff-filter=A', '--no-renames', '--relative', '--name-only', '-z', '--format=%x01%ct', '--', '*.php'],
        ));

        $addedOn = [];
        foreach ($paths as $path) {
            $addedOn[$path] = $added[$path] ?? null;
        }

        return new self($addedOn, true, $sources, $sha, $pathPrefix, $leftOut);
    }

    /**
     * @param string $log output of `git log --name-only -z --format=%x01%ct`
     *
     * @return array<string, string> by path, the UTC day of the latest commit listing it
     */
    public static function parseAdditions(string $log): array
    {
        $added = [];
        foreach (explode("\x01", $log) as $commit) {
            $timestampLength = strspn($commit, '0123456789');
            if ($timestampLength === 0) {
                continue;
            }
            $day = gmdate('Y-m-d', (int) substr($commit, 0, $timestampLength));

            foreach (explode("\0", substr($commit, $timestampLength)) as $path) {
                $path = ltrim($path, "\n");
                if ($path !== '' && $day > ($added[$path] ?? '')) {
                    $added[$path] = $day;
                }
            }
        }

        return $added;
    }

    /**
     * @param list<string> $arguments
     */
    private static function git(string $projectDir, array $arguments): string
    {
        $process = @proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectDir);
        if ($process === false) {
            throw new RuntimeException(sprintf('Could not run git in "%s".', $projectDir));
        }

        $output = (string) stream_get_contents($pipes[1]);
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(sprintf('"git %s" failed in "%s": %s', $arguments[0] ?? '', $projectDir, $error));
        }

        return $output;
    }
}
