<?php

declare(strict_types=1);

namespace Deadcode\Report;

/**
 * What the report reads from one PHP file of the working tree.
 */
final class SourceFile
{
    private const BLANK = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    private const CLASS_LIKE = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];

    /**
     * @param int          $lines   physical lines
     * @param list<string> $members `Class::member` for every method, class constant and enum case that the named
     *                              classes, interfaces, traits and enums of the file declare
     */
    public function __construct(
        public readonly int $lines,
        public readonly array $members,
    ) {
    }

    public static function parse(string $code): self
    {
        return new self(
            substr_count($code, "\n") + (int) ($code !== '' && !str_ends_with($code, "\n")),
            self::members($code),
        );
    }

    /**
     * @return list<string>
     */
    private static function members(string $code): array
    {
        $tokens = [];
        foreach (token_get_all($code) as $token) {
            if (!is_array($token)) {
                $tokens[] = [null, $token];
            } elseif (!in_array($token[0], self::BLANK, true)) {
                $tokens[] = [$token[0], $token[1]];
            }
        }

        $members = [];
        $namespace = '';
        $class = null;
        $memberDepth = 0;
        $depth = 0;
        foreach ($tokens as $index => [$id, $text]) {
            [$nextId, $nextText] = $tokens[$index + 1] ?? [null, ''];

            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || $text === '{') {
                ++$depth;
            } elseif ($text === '}') {
                --$depth;
                if ($depth < $memberDepth) {
                    $class = null;
                }
            } elseif ($id === T_NAMESPACE && ($nextId === T_STRING || $nextId === T_NAME_QUALIFIED)) {
                $namespace = $nextText . '\\';
            } elseif ($id === T_NAMESPACE && $nextText === '{') {
                $namespace = '';
            } elseif (
                $class === null
                && in_array($id, self::CLASS_LIKE, true)
                && $nextId === T_STRING
                && ($tokens[$index - 1][0] ?? null) !== T_DOUBLE_COLON
            ) {
                $class = $namespace . $nextText;
                $memberDepth = $depth + 1;
            } elseif ($class === null || $depth !== $memberDepth) {
                continue;
            } elseif ($id === T_FUNCTION) {
                $name = $nextText === '&' ? ($tokens[$index + 2][1] ?? '') : $nextText;
                $members[] = $class . '::' . $name;
            } elseif ($id === T_CASE) {
                $members[] = $class . '::' . $nextText;
            } elseif ($id === T_CONST) {
                foreach (self::constantNames($tokens, $index + 1) as $name) {
                    $members[] = $class . '::' . $name;
                }
            }
        }

        return $members;
    }

    /**
     * The names a `const` statement declares: in `const int A = 1, B = [2, 3];` the token before each `=`
     * that is not inside a value.
     *
     * @param list<array{int|null, string}> $tokens
     *
     * @return list<string>
     */
    private static function constantNames(array $tokens, int $from): array
    {
        $names = [];
        $nesting = 0;
        $inValue = false;
        for ($index = $from; isset($tokens[$index]) && $tokens[$index][1] !== ';'; ++$index) {
            $text = $tokens[$index][1];
            if ($text === '(' || $text === '[') {
                ++$nesting;
            } elseif ($text === ')' || $text === ']') {
                --$nesting;
            } elseif ($text === '=' && !$inValue) {
                $names[] = $tokens[$index - 1][1];
                $inValue = true;
            } elseif ($text === ',' && $nesting === 0) {
                $inValue = false;
            }
        }

        return $names;
    }
}
