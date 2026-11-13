<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * A list of path patterns, relative to the project directory and written with forward slashes.
 *
 * `*` stands for any run of characters inside one path segment, `?` for one such character and `**` for any
 * run of characters across segments. A `**` followed by a slash also stands for nothing at all, so a pattern
 * can name a file at any depth below a directory, that directory included. Everything else is literal and
 * case-sensitive. A pattern that matches a directory covers every file below it, so `src`, `src/` and
 * `src/**` say the same.
 */
final class Globs
{
    /** @var list<string> */
    private readonly array $regexes;

    /**
     * @param list<string> $patterns
     */
    public function __construct(public readonly array $patterns)
    {
        $this->regexes = array_map(self::regex(...), $patterns);
    }

    public function match(string $path): bool
    {
        foreach ($this->regexes as $regex) {
            if (preg_match($regex, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function regex(string $pattern): string
    {
        $regex = '';
        foreach (preg_split('~(\*\*/|\*\*|\*|\?)~', rtrim($pattern, '/'), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            $regex .= match ($part) {
                '**/' => '(?:.*/)?',
                '**' => '.*',
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($part, '~'),
            };
        }

        return '~\A' . $regex . '(?:/.*)?\z~s';
    }
}
