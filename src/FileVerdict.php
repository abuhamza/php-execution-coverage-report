<?php

declare(strict_types=1);

namespace Deadcode\Report;

final class FileVerdict
{
    /**
     * @param string|null        $lastSeen latest day with a hit, null when the file was never seen
     * @param string|null        $addedOn  day the file was added to git, null when history does not say
     * @param int                $lines    physical lines in the working tree, 0 when the file is not there
     * @param StaticVerdict|null $static   null when the report was given no static findings
     */
    public function __construct(
        public readonly string $path,
        public readonly Verdict $verdict,
        public readonly int $hits,
        public readonly ?string $lastSeen,
        public readonly ?string $addedOn,
        public readonly int $lines = 0,
        public readonly ?StaticVerdict $static = null,
    ) {
    }

    public function quadrant(): ?Quadrant
    {
        return match (true) {
            $this->static === StaticVerdict::Unused && $this->verdict->coldOrDead() => Quadrant::DeleteNow,
            $this->static === StaticVerdict::Used && $this->verdict->coldOrDead() => Quadrant::Investigate,
            $this->static === StaticVerdict::Unused && $this->verdict === Verdict::Alive => Quadrant::StaticFalsePositive,
            default => null,
        };
    }
}
