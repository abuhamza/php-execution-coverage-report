<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * The tracked files one directory holds directly, not those of its subdirectories.
 */
final class DirectoryTally
{
    /**
     * @param string $directory  project-relative, `.` for the project directory itself
     * @param int    $coldOrDead files labelled early cold, cold or dead
     */
    public function __construct(
        public readonly string $directory,
        public readonly int $files,
        public readonly int $coldOrDead,
    ) {
    }

    public function fullyCold(): bool
    {
        return $this->coldOrDead === $this->files;
    }
}
