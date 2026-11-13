<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * Files someone decided to keep although they are not seen running, and why.
 */
final class AllowlistEntry
{
    /**
     * @param string|null $expires first day the entry no longer applies, null when it never lapses
     */
    public function __construct(
        public readonly Globs $paths,
        public readonly string $reason,
        public readonly ?string $expires = null,
    ) {
    }

    public function expired(string $today): bool
    {
        return $this->expires !== null && $this->expires <= $today;
    }
}
