<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * Which installed Composer packages production never loaded a file of.
 */
final class PackageUsage
{
    /**
     * @param int           $installed   every installed package
     * @param int           $devOnly     packages left out because production does not install them
     * @param int           $notLoadable other packages left out because production can never report a file of them
     * @param list<string>  $gaps        every reason collection is too thin to call a package unused, empty when it is not
     * @param list<Package> $unused      the remaining packages without any hit in the data, sorted by name;
     *                                   empty when there are gaps
     */
    public function __construct(
        public readonly int $installed,
        public readonly int $devOnly,
        public readonly int $notLoadable,
        public readonly array $gaps,
        public readonly array $unused,
    ) {
    }

    public function judged(): bool
    {
        return $this->gaps === [];
    }
}
