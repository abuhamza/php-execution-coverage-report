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
     * @param string|null                                                                                     $firstDay  earliest day with an accepted batch (with a stored hit, from an
     *                                                                                                                       older collector), null when there is none
     * @param string|null                                                                                     $lastDay   latest such day, null when there is none
     * @param array<string, array{hits: int, first_seen: string, last_seen: string}>                          $files     by project-relative path
     * @param list<array{host: string, source: string, first_seen: string, last_seen: string, sha: string}> $reporters one entry per (host, source)
     * @param array<string, array{first_day: string, last_day: string, missing: list<array{string, string}>}>|null $batchDays by source: first and last day with a batch and
     *                                                                                                                       the stretches without one in between, as first and
     *                                                                                                                       last missing day; null from a collector that does
     *                                                                                                                       not record them
     */
    public function __construct(
        public readonly ?string $firstDay,
        public readonly ?string $lastDay,
        public readonly array $files,
        public readonly array $reporters,
        public readonly ?array $batchDays = null,
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
            array_key_exists('batch_days', $export) ? self::batchDays($export['batch_days']) : null,
        );
    }

    /**
     * @return array<string, array{first_day: string, last_day: string, missing: list<array{string, string}>}>
     */
    private static function batchDays(mixed $value): array
    {
        if (!is_array($value)) {
            throw self::notUnderstood();
        }

        $batchDays = [];
        foreach ($value as $source => $days) {
            if (!is_array($days) || !is_array($days['missing'] ?? null) || !array_is_list($days['missing'])) {
                throw self::notUnderstood();
            }
            $missing = [];
            foreach ($days['missing'] as $stretch) {
                if (!is_array($stretch) || !array_is_list($stretch) || count($stretch) !== 2) {
                    throw self::notUnderstood();
                }
                $missing[] = [self::day($stretch[0]), self::day($stretch[1])];
            }
            $batchDays[(string) $source] = [
                'first_day' => self::day($days['first_day'] ?? null),
                'last_day' => self::day($days['last_day'] ?? null),
                'missing' => $missing,
            ];
        }
        ksort($batchDays);

        return $batchDays;
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
