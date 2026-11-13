<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * Whether collection covered one window, which decides if its label may be issued.
 */
final class WindowCoverage
{
    /**
     * @param string       $start first day of the window
     * @param list<string> $gaps  every reason collection did not cover the window, empty when it did
     */
    public function __construct(
        public readonly Window $window,
        public readonly string $start,
        public readonly array $gaps,
    ) {
    }

    public function judged(): bool
    {
        return $this->gaps === [];
    }
}
