<?php

declare(strict_types=1);

namespace Deadcode\Report;

use DateTimeImmutable;
use DateTimeZone;

/**
 * UTC calendar days as `YYYY-MM-DD` strings, which compare correctly as plain strings.
 */
final class Day
{
    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    public static function isValid(string $day): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $day;
    }

    public static function minus(string $day, int $days): string
    {
        return self::plus($day, -$days);
    }

    public static function plus(string $day, int $days): string
    {
        return self::date($day)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    /**
     * Whole days from $from to $to, negative when $to is the earlier one.
     */
    public static function between(string $from, string $to): int
    {
        return (int) self::date($from)->diff(self::date($to))->format('%r%a');
    }

    private static function date(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable($day, new DateTimeZone('UTC'));
    }
}
