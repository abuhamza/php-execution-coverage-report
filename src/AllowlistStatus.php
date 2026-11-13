<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * What one allowlist entry does on the report day.
 */
final class AllowlistStatus
{
    /**
     * @param int          $files      first-party files the paths of the entry match
     * @param list<string> $coldOrDead those of them labelled early cold, cold or dead, sorted: the files the
     *                                 entry covers, or covered before it expired
     */
    public function __construct(
        public readonly AllowlistEntry $entry,
        public readonly bool $expired,
        public readonly int $files,
        public readonly array $coldOrDead,
    ) {
    }

    public function stale(): bool
    {
        return $this->files === 0;
    }
}
