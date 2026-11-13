<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * Where the runtime label and the static verdict of a file meet. A file that is alive on both sides has none.
 */
enum Quadrant: string
{
    /** Statically unused, and early cold, cold or dead. */
    case DeleteNow = 'delete_now';
    /** Statically used, and early cold, cold or dead. */
    case Investigate = 'investigate';
    /** Statically unused, yet alive. */
    case StaticFalsePositive = 'static_false_positive';
}
