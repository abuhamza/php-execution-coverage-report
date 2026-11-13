<?php

declare(strict_types=1);

namespace Deadcode\Report;

use DateTimeInterface;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * What the application says about itself: which tracked files are its own code, and which of them it keeps
 * on purpose although they are not seen running.
 */
final class Config
{
    public const FILE = 'deadcode.yaml';

    /**
     * Left out, along with what `exclude` names, when the config names no `include`: PHP files under `config/` are read when the container is
     * built and migrations run once, both at deploy time, so they would be listed as dead forever.
     */
    public const DEFAULT_EXCLUDE = ['config/', 'migrations/', 'tests/', 'vendor/'];

    /**
     * @param string|null          $file      the config file as the report names it, null when there is none
     * @param list<AllowlistEntry> $allowlist
     */
    public function __construct(
        public readonly ?string $file,
        public readonly Globs $include,
        public readonly Globs $exclude,
        public readonly array $allowlist = [],
    ) {
    }

    public static function default(): self
    {
        return new self(null, new Globs(['**']), new Globs(self::DEFAULT_EXCLUDE));
    }

    /**
     * @param string|null $file null to read `deadcode.yaml` in the project directory when it is there
     */
    public static function load(string $projectDir, ?string $file = null): self
    {
        $path = $file ?? $projectDir . '/' . self::FILE;
        if ($file === null && !is_file($path)) {
            return self::default();
        }

        $yaml = @file_get_contents($path);
        if ($yaml === false) {
            throw new RuntimeException(sprintf('Could not read the config file "%s".', $path));
        }

        return self::fromYaml($yaml, $file ?? self::FILE);
    }

    public static function fromYaml(string $yaml, string $file): self
    {
        try {
            $data = Yaml::parse($yaml, Yaml::PARSE_DATETIME) ?? [];
        } catch (ParseException $exception) {
            throw new RuntimeException(sprintf('%s: not valid YAML: %s', $file, $exception->getMessage()), 0, $exception);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RuntimeException(sprintf('%s: the file must hold the keys include, exclude and allowlist, not a list or a single value.', $file));
        }
        self::rejectUnknownKeys($data, ['include', 'exclude', 'allowlist'], $file, 'the file');

        $entries = $data['allowlist'] ?? [];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new RuntimeException(sprintf('%s: allowlist must be a list of entries.', $file));
        }
        $allowlist = [];
        foreach ($entries as $index => $entry) {
            $allowlist[] = self::entry($entry, $file, sprintf('allowlist entry %d', $index + 1));
        }

        return new self(
            $file,
            new Globs(array_key_exists('include', $data) ? self::patterns($data['include'], $file, 'include') : ['**']),
            new Globs([
                ...(array_key_exists('include', $data) ? [] : self::DEFAULT_EXCLUDE),
                ...(array_key_exists('exclude', $data) ? self::patterns($data['exclude'], $file, 'exclude', true) : []),
            ]),
            $allowlist,
        );
    }

    public function firstParty(string $path): bool
    {
        return $this->include->match($path) && !$this->exclude->match($path);
    }

    private static function entry(mixed $entry, string $file, string $name): AllowlistEntry
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new RuntimeException(sprintf('%s: %s must hold the keys paths, reason and expires.', $file, $name));
        }
        self::rejectUnknownKeys($entry, ['paths', 'reason', 'expires'], $file, $name);

        $reason = $entry['reason'] ?? null;
        if (!is_string($reason) || trim($reason) === '') {
            throw new RuntimeException(sprintf('%s: %s has no reason. Say in a sentence why these files are kept.', $file, $name));
        }

        $expires = null;
        if (array_key_exists('expires', $entry)) {
            $expires = $entry['expires'] instanceof DateTimeInterface ? $entry['expires']->format('Y-m-d\TH:i:s') : $entry['expires'];
            $expires = is_string($expires) ? preg_replace('~T00:00:00\z~', '', $expires) : null;
            if ($expires === null || !Day::isValid($expires)) {
                throw new RuntimeException(sprintf('%s: %s has an expires value that is not a day written as YYYY-MM-DD.', $file, $name));
            }
        }

        return new AllowlistEntry(
            new Globs(self::patterns($entry['paths'] ?? null, $file, 'paths of ' . $name)),
            trim($reason),
            $expires,
        );
    }

    /**
     * @return list<string>
     */
    private static function patterns(mixed $value, string $file, string $name, bool $mayBeEmpty = false): array
    {
        if (!is_array($value) || !array_is_list($value) || ($value === [] && !$mayBeEmpty)) {
            throw new RuntimeException(sprintf('%s: %s must be a list of %s.', $file, $name, $mayBeEmpty ? 'path patterns' : 'at least one path pattern'));
        }

        $patterns = [];
        foreach ($value as $pattern) {
            if (!is_string($pattern) || trim($pattern, '/') === '' || str_starts_with($pattern, '/')) {
                throw new RuntimeException(sprintf(
                    '%s: %s holds %s, which is not a path pattern relative to the project directory.',
                    $file,
                    $name,
                    json_encode($pattern, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                ));
            }
            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /**
     * @param array<mixed> $map
     * @param list<string> $known
     */
    private static function rejectUnknownKeys(array $map, array $known, string $file, string $name): void
    {
        $unknown = array_diff(array_keys($map), $known);
        if ($unknown !== []) {
            throw new RuntimeException(sprintf(
                '%s: %s has the unknown key "%s". Known keys: %s.',
                $file,
                $name,
                (string) reset($unknown),
                implode(', ', $known),
            ));
        }
    }
}
