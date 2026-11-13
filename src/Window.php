<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * An observation window in days, strongest label first. A file falls in a window when it has no hit
 * on or after the day the window starts.
 */
enum Window: int
{
    case Dead = 90;
    case Cold = 30;
    case EarlyCold = 14;

    public function verdict(): Verdict
    {
        return match ($this) {
            self::Dead => Verdict::Dead,
            self::Cold => Verdict::Cold,
            self::EarlyCold => Verdict::EarlyCold,
        };
    }

    public function startsOn(string $today): string
    {
        return Day::minus($today, $this->value);
    }
}
