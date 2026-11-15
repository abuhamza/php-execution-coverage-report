<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * One package Composer installed in the project.
 */
final class Package
{
    private const COMPOSER_PLUGIN = 'composer-plugin';

    /**
     * @param string      $version  as Composer prints it, for example `v6.4.1` or `dev-main`
     * @param string|null $path     project-relative directory the package is installed in, as the reporter sees it;
     *                              null when the package has no directory inside the project
     * @param bool        $dev      true when only `require-dev` brings the package in
     * @param bool        $direct   true when the project's own `composer.json` requires the package
     * @param int         $phpFiles PHP files in the package directory
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $type,
        public readonly ?string $path,
        public readonly bool $dev,
        public readonly bool $direct,
        public readonly int $phpFiles,
    ) {
    }

    /**
     * False when production can never report a file of the package: a metapackage, a package of assets only,
     * a package installed outside the project, or a Composer plugin, which runs inside Composer.
     */
    public function loadable(): bool
    {
        return $this->path !== null && $this->phpFiles > 0 && $this->type !== self::COMPOSER_PLUGIN;
    }
}
