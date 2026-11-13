<?php

declare(strict_types=1);

namespace Deadcode\Report;

final class ReporterStatus
{
    /**
     * @param int $daysSilent days from the last batch to the report day, 0 when it reported that day or later
     */
    public function __construct(
        public readonly string $host,
        public readonly string $source,
        public readonly string $firstSeen,
        public readonly string $lastSeen,
        public readonly int $daysSilent,
    ) {
    }

    public function silent(): bool
    {
        return $this->daysSilent > Analysis::SILENT_AFTER_DAYS;
    }
}
