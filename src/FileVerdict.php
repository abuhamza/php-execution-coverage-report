<?php

declare(strict_types=1);

namespace Deadcode\Report;

final class FileVerdict
{
    /**
     * @param string|null $lastSeen latest day with a hit, null when the file was never seen
     * @param string|null $addedOn  day the file was added to git, null when history does not say
     */
    public function __construct(
        public readonly string $path,
        public readonly Verdict $verdict,
        public readonly int $hits,
        public readonly ?string $lastSeen,
        public readonly ?string $addedOn,
    ) {
    }
}
