<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Finds calls to Pest's global visit() in PHP source, ignoring comments and strings.
 *
 * Token-based on purpose: tests/Browser files carry long header comments that
 * mention visit() by name, and a regex over the raw text would flag every one
 * of them (or, worse, be "fixed" by excluding the files).
 *
 * A call is exempt when a comment on the SAME line contains `clock-pin-exempt`.
 * That marker is a decision, not a convenience: it says this page is not pinned
 * to tests/Browser/Support/BrowserClock and why.
 */
final class BareVisitScanner
{
    public const EXEMPT_MARKER = 'clock-pin-exempt';

    /**
     * @return array{bare: array<int, int>, exempt: array<int, int>} line numbers
     */
    public static function scan(string $source): array
    {
        $tokens = token_get_all($source);
        $commentsByLine = [];
        $calls = [];

        // `use function Pest\Browser\visit as open;` makes `open(...)` a visit() too.
        $callable = ['visit'];

        if (preg_match_all('/use\s+function\s+[\w\\\\]*\bvisit\s+as\s+(\w+)/i', $source, $aliases) > 0) {
            array_push($callable, ...array_map('strtolower', $aliases[1]));
        }

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                $commentsByLine[$line] = ($commentsByLine[$line] ?? '').$text;

                continue;
            }

            // PHP 8 tokenizes `\visit` and `\Pest\Browser\visit` as ONE name token,
            // not as T_STRING, so a check on bare names alone misses both. A name
            // in any namespace whose LAST segment is a visit callable counts:
            // over-flagging costs an exemption marker, under-flagging costs the guard.
            if (! in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }

            $lastSegment = strtolower(substr(strrchr('\\'.$text, '\\'), 1));

            if (! in_array($lastSegment, $callable, true)) {
                continue;
            }

            $previous = self::neighbour($tokens, $i, -1);
            $next = self::neighbour($tokens, $i, 1);

            $isMethodOrStatic = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            $isDeclaration = is_array($previous) && in_array($previous[0], [T_FUNCTION, T_USE, T_NEW], true);

            if ($next === '(' && ! $isMethodOrStatic && ! $isDeclaration) {
                $calls[] = $line;
            }
        }

        $bare = [];
        $exempt = [];

        foreach ($calls as $line) {
            if (str_contains($commentsByLine[$line] ?? '', self::EXEMPT_MARKER)) {
                $exempt[] = $line;
            } else {
                $bare[] = $line;
            }
        }

        return ['bare' => $bare, 'exempt' => $exempt];
    }

    /**
     * Every collected browser test, subdirectories included. phpunit.xml collects
     * tests/Browser recursively, so a flat glob would let tests/Browser/Sub/XTest.php
     * keep a bare visit() without a word from the guard.
     *
     * @return array<int, string> absolute paths, sorted
     */
    public static function testFilesUnder(string $directory): array
    {
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The nearest token in the given direction that is not whitespace or a comment.
     *
     * @param  array<int, mixed>  $tokens
     */
    private static function neighbour(array $tokens, int $index, int $step): mixed
    {
        for ($j = $index + $step; isset($tokens[$j]); $j += $step) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
