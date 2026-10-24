<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * What a test file looks like to the guard in tests/Unit/TestFilesGuardTest.php.
 *
 * The failure it exists for is silent: a test file that lost its `<?php` tag is
 * just text to PHP, Pest finds no tests in it, and the suite stays green with
 * fewer tests than it had yesterday. Nothing is red, so nothing is looked at.
 */
final class TestFileInspector
{
    /**
     * Does the file open with a PHP tag — at byte 0, no BOM, no blank line?
     */
    public static function opensWithPhpTag(string $source): bool
    {
        return preg_match('/\A<\?php\s/', $source) === 1;
    }

    /**
     * How many calls to Pest's test() or it() the file contains. Token based:
     * the header comments of these files talk about it() and test() constantly,
     * and `$x->test()`, `Foo::it()` or a function named test are not tests.
     */
    public static function countTests(string $source): int
    {
        $tokens = token_get_all($source);
        $count = 0;

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array(strtolower($token[1]), ['test', 'it'], true)) {
                continue;
            }

            $previous = self::neighbour($tokens, $i, -1);
            $next = self::neighbour($tokens, $i, 1);

            $notAFunctionCall = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);

            if ($next === '(' && ! $notAFunctionCall) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Files that exist but that Pest did not list.
     *
     * @param  array<int, string>  $files  paths relative to tests/, e.g. Unit/ExampleTest.php
     * @param  array<int, string>  $collected  the same notation, from `pest --list-tests`
     * @return array<int, string>
     */
    public static function uncollected(array $files, array $collected): array
    {
        return array_values(array_diff($files, $collected));
    }

    /**
     * `P\Tests\Feature\Auth\AuthenticationTest::__pest_evaluable_…` -> `Feature/Auth/AuthenticationTest.php`
     *
     * @return array<int, string> distinct, sorted
     */
    public static function filesFromListing(string $listing): array
    {
        preg_match_all('/^\s*-\s*P\\\\Tests\\\\(\S+?)::/m', $listing, $matches);

        $files = array_map(fn (string $class): string => str_replace('\\', '/', $class).'.php', $matches[1]);
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
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
