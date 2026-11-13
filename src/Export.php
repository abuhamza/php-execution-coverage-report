<?php

declare(strict_types=1);

namespace Deadcode\Report;

use RuntimeException;

/**
 * What the collector holds for one app, as returned by `GET /export` (wire format v1).
 */
final class Export
{
    /**
     * @param string|null                                                                                     $firstDay  earliest day with a stored hit, null when there is none
     * @param string|null                                                                                     $lastDay   latest day with a stored hit, null when there is none
     * @param array<string, array{hits: int, first_seen: string, last_seen: string}>                          $files     by project-relative path
     * @param list<array{host: string, source: string, first_seen: string, last_seen: string, sha: string}> $reporters one entry per (host, source)
     */
    public function __construct(
        public readonly ?string $firstDay,
        public readonly ?string $lastDay,
        public readonly array $files,
        public readonly array $reporters,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $export = json_decode($json, true);
        if (
            !is_array($export)
            || ($export['v'] ?? null) !== 1
            || !is_array($export['files'] ?? null)
            || !is_array($export['reporters'] ?? null)
        ) {
            throw self::notUnderstood();
        }

        $files = [];
        foreach ($export['files'] as $path => $file) {
            if (!is_array($file) || !is_int($file['hits'] ?? null)) {
                throw self::notUnderstood();
            }
            $files[(string) $path] = [
                'hits' => $file['hits'],
                'first_seen' => self::day($file['first_seen'] ?? null),
                'last_seen' => self::day($file['last_seen'] ?? null),
            ];
        }

        $reporters = [];
        foreach ($export['reporters'] as $reporter) {
            if (
                !is_array($reporter)
                || !is_string($reporter['host'] ?? null)
                || !is_string($reporter['source'] ?? null)
                || !is_string($reporter['sha'] ?? null)
            ) {
                throw self::notUnderstood();
            }
            $reporters[] = [
                'host' => $reporter['host'],
                'source' => $reporter['source'],
                'first_seen' => self::day($reporter['first_seen'] ?? null),
                'last_seen' => self::day($reporter['last_seen'] ?? null),
                'sha' => $reporter['sha'],
            ];
        }

        return new self(
            ($export['first_day'] ?? null) === null ? null : self::day($export['first_day']),
            ($export['last_day'] ?? null) === null ? null : self::day($export['last_day']),
            $files,
            $reporters,
        );
    }

    private static function day(mixed $value): string
    {
        if (!is_string($value) || !Day::isValid($value)) {
            throw self::notUnderstood();
        }

        return $value;
    }

    private static function notUnderstood(): RuntimeException
    {
        return new RuntimeException('The collector returned an export this report does not understand.');
    }
}
