<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * What the report concludes about one tracked file. A file gets exactly one.
 */
enum Verdict: string
{
    /** Hit within the shortest window. */
    case Alive = 'alive';
    case EarlyCold = 'early cold';
    case Cold = 'cold';
    case Dead = 'dead';
    /** No recent hit, but added to git more recently than every window it has no hit in. */
    case TooYoung = 'too young';
    /** No recent hit, and git history does not say when the file was added. */
    case NoHistory = 'no history';
    /** No recent hit and old enough for a window, but collection did not cover that window. */
    case NotJudged = 'not judged';

    /**
     * Whether this is one of the labels a window issues for a file without a hit.
     */
    public function coldOrDead(): bool
    {
        return $this === self::EarlyCold || $this === self::Cold || $this === self::Dead;
    }
}
