<?php

declare(strict_types=1);

namespace Deadcode\Report;

use RuntimeException;

/**
 * The members shipmonk/dead-code-detector reports dead, read from PHPStan's `--error-format=json` output.
 *
 * The detector reports members, never classes or files. A dead member that is only reachable from another
 * dead member has no error of its own: it is named in the tip of that other error, possibly from another file.
 */
final class StaticFindings
{
    private const IDENTIFIERS = ['shipmonk.deadMethod', 'shipmonk.deadConstant', 'shipmonk.deadEnumCase'];

    /** @var array<string, true> by lower-case `Class::member` */
    private readonly array $dead;

    /**
     * @param list<string> $deadMembers `Class::member` for each dead method, class constant and enum case
     */
    public function __construct(array $deadMembers)
    {
        $this->dead = array_fill_keys(array_map('strtolower', $deadMembers), true);
    }

    public static function fromJson(string $json): self
    {
        $output = json_decode($json, true);
        if (!is_array($output) || !is_array($output['files'] ?? null) || !is_array($output['errors'] ?? null)) {
            throw new RuntimeException('The static file is not the JSON output of PHPStan (--error-format=json).');
        }
        if ($output['errors'] !== []) {
            $first = reset($output['errors']);
            throw new RuntimeException(
                'The static file reports that PHPStan did not analyse the project cleanly: ' . (is_string($first) ? $first : 'unknown error'),
            );
        }

        $dead = [];
        foreach ($output['files'] as $file) {
            foreach (is_array($file) && is_array($file['messages'] ?? null) ? $file['messages'] : [] as $message) {
                if (
                    !is_array($message)
                    || !in_array($message['identifier'] ?? null, self::IDENTIFIERS, true)
                    || !is_string($message['message'] ?? null)
                    || preg_match('/^Unused (\S+::\S+)/', $message['message'], $direct) !== 1
                ) {
                    continue;
                }
                $dead[] = $direct[1];

                if (is_string($message['tip'] ?? null)) {
                    preg_match_all('/Thus (\S+::\S+) is transitively unused/', $message['tip'], $transitive);
                    array_push($dead, ...$transitive[1]);
                }
            }
        }

        return new self($dead);
    }

    public function verdict(SourceFile $file): StaticVerdict
    {
        if ($file->members === []) {
            return StaticVerdict::None;
        }
        foreach ($file->members as $member) {
            if (!isset($this->dead[strtolower($member)])) {
                return StaticVerdict::Used;
            }
        }

        return StaticVerdict::Unused;
    }
}
