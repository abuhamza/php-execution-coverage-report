<?php

declare(strict_types=1);

namespace Deadcode\Report;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * The packages Composer installed in a project, read from the `installed.json` it writes in the vendor directory.
 */
final class InstalledPackages
{
    /**
     * @param list<Package> $packages sorted by name
     */
    public function __construct(public readonly array $packages)
    {
    }

    /**
     * Null when the project has no `installed.json`, which is the case before `composer install`.
     */
    public static function read(string $projectDir): ?self
    {
        $root = realpath($projectDir);
        if ($root === false) {
            return null;
        }

        $composer = is_file($root . '/composer.json') ? json_decode((string) file_get_contents($root . '/composer.json'), true) : null;
        $composer = is_array($composer) ? $composer : [];
        $config = is_array($composer['config'] ?? null) ? $composer['config'] : [];
        $vendorDir = is_string($config['vendor-dir'] ?? null) ? trim($config['vendor-dir'], '/') : 'vendor';
        $required = is_array($composer['require'] ?? null) ? $composer['require'] : [];

        $metadataDir = $root . '/' . $vendorDir . '/composer';
        if (!is_file($metadataDir . '/installed.json')) {
            return null;
        }
        $installed = json_decode((string) file_get_contents($metadataDir . '/installed.json'), true);
        if (!is_array($installed) || !is_array($installed['packages'] ?? null)) {
            throw new RuntimeException(sprintf(
                'Could not read the installed packages from "%s/composer/installed.json": Composer 2 writes a "packages" list there.',
                $vendorDir,
            ));
        }
        $devNames = is_array($installed['dev-package-names'] ?? null) ? $installed['dev-package-names'] : [];

        $packages = [];
        foreach ($installed['packages'] as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null)) {
                continue;
            }
            $installPath = $package['install-path'] ?? null;
            $dir = is_string($installPath)
                ? realpath(str_starts_with($installPath, '/') ? $installPath : $metadataDir . '/' . $installPath)
                : false;
            $inProject = $dir !== false && str_starts_with($dir, $root . '/');

            $packages[] = new Package(
                $package['name'],
                is_string($package['version'] ?? null) ? $package['version'] : '',
                is_string($package['type'] ?? null) ? $package['type'] : 'library',
                $inProject ? substr($dir, strlen($root) + 1) : null,
                in_array($package['name'], $devNames, true),
                isset($required[$package['name']]),
                $inProject ? self::phpFiles($dir) : 0,
            );
        }
        usort($packages, static fn (Package $a, Package $b): int => $a->name <=> $b->name);

        return new self($packages);
    }

    private static function phpFiles(string $dir): int
    {
        $count = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        return $count;
    }
}
