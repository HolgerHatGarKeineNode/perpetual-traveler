<?php

use Tests\Support\TestFileInspector;

/*
 | THE SILENT TEST FILE. A file under tests/ that stops running does not turn
 | anything red: lose the `<?php` tag and PHP prints the file as text, rename
 | Foo*Test.php to Foo*Tests.php and phpunit.xml's suffix skips it, move it out
 | of the three suite directories and nothing collects it. The suite stays
 | green, with fewer tests. Measured in another repo, where exactly that ran
 | unnoticed; the counts here are the guard against the same thing in this one.
 |
 | Three claims, each with its positive control at the bottom:
 |   1. every *Test.php opens with `<?php` and declares at least one test()/it();
 |   2. every one of them is LISTED by Pest (`pest --list-tests`) — collected is
 |      what Pest says, not what the directory layout suggests;
 |   3. a file that declares tests but is not named *Test.php is named here, with
 |      the reason, rather than discovered.
 */

/** @return array<int, string> paths relative to tests/ */
$testFiles = function (): array {
    $root = dirname(__DIR__);
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);

        // tests/js is node --test, run by tests/js/zones.mjs and gated there.
        if ($file->getExtension() === 'php' && ! str_starts_with($relative, 'js/')) {
            $files[] = $relative;
        }
    }

    sort($files);

    return $files;
};

/** Files that hold tests on purpose and are NOT collected, with the reason. */
const HELD_BACK = [
    'Browser/ReferenceCapture.php' => 'run by hand (composer browser:capture-references); named without the Test suffix on purpose, see its header',
];

test('every collected-looking test file opens with a PHP tag and declares at least one test', function () use ($testFiles) {
    $named = array_values(array_filter($testFiles(), fn (string $f) => str_ends_with($f, 'Test.php')));

    // Lower bound: a finder that matched nothing would make this vacuous.
    expect(count($named))->toBeGreaterThanOrEqual(25);

    $broken = [];

    foreach ($named as $file) {
        $source = file_get_contents(dirname(__DIR__).'/'.$file);

        if (! TestFileInspector::opensWithPhpTag($source)) {
            $broken[] = $file.' (does not open with <?php)';
        } elseif (TestFileInspector::countTests($source) === 0) {
            $broken[] = $file.' (declares no test() or it())';
        }
    }

    expect($broken)->toBe([], 'Silent test files: '.implode(', ', $broken));
});

test('Pest lists every test file, and lists nothing that is not a file', function () use ($testFiles) {
    $named = array_values(array_filter($testFiles(), fn (string $f) => str_ends_with($f, 'Test.php')));

    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/vendor/bin/pest').' --list-tests --no-tia --colors=never 2>&1';
    $listing = (string) shell_exec($command);
    $listed = TestFileInspector::filesFromListing($listing);

    // A listing that failed to run is not an empty suite.
    expect(count($listed))->toBeGreaterThanOrEqual(25, 'pest --list-tests produced no usable listing: '.substr($listing, 0, 300));

    $unlisted = TestFileInspector::uncollected($named, $listed);
    expect($unlisted)->toBe([], 'Test files that exist but that Pest does not collect (suffix, directory or syntax): '.implode(', ', $unlisted));

    $unseen = TestFileInspector::uncollected($listed, $testFiles());
    expect($unseen)->toBe([], 'Pest lists a file this scan does not see: '.implode(', ', $unseen));
});

test('a file that holds tests but is not named *Test.php is a named exception', function () use ($testFiles) {
    $unnamed = [];

    foreach ($testFiles() as $file) {
        if (str_ends_with($file, 'Test.php')) {
            continue;
        }

        $source = file_get_contents(dirname(__DIR__).'/'.$file);

        if (TestFileInspector::countTests($source) > 0 && ! array_key_exists($file, HELD_BACK)) {
            $unnamed[] = $file;
        }
    }

    expect($unnamed)->toBe([], 'These files declare tests that no run will ever collect (rename to *Test.php or add to HELD_BACK with a reason): '.implode(', ', $unnamed));

    // The exception list must not rot either: a held-back file that is gone, or
    // that no longer holds tests, should leave the list.
    foreach (array_keys(HELD_BACK) as $file) {
        expect(dirname(__DIR__).'/'.$file)->toBeFile()
            ->and(TestFileInspector::countTests(file_get_contents(dirname(__DIR__).'/'.$file)))->toBeGreaterThan(0, $file.' is in HELD_BACK but declares no tests');
    }
});

// POSITIVE CONTROLS — the inspector fed the shapes the guard has to tell apart.
test('the inspector tells a silent file from a live one', function () {
    $live = "<?php\n\nit('does a thing', function () { expect(true)->toBeTrue(); });\n";

    expect(TestFileInspector::opensWithPhpTag($live))->toBeTrue()
        ->and(TestFileInspector::countTests($live))->toBe(1);

    // Lost its opening tag: PHP sees text. The body still LOOKS like a test file.
    $noTag = "it('does a thing', function () { expect(true)->toBeTrue(); });\n";
    expect(TestFileInspector::opensWithPhpTag($noTag))->toBeFalse();

    // A leading blank line or a BOM before the tag is output, not a tag at byte 0.
    expect(TestFileInspector::opensWithPhpTag("\n<?php\nit('x', fn () => 1);"))->toBeFalse();
    expect(TestFileInspector::opensWithPhpTag("\xEF\xBB\xBF<?php\nit('x', fn () => 1);"))->toBeFalse();

    // Tag present, no test: prose, helpers, method calls and a string do not count.
    $empty = "<?php\n// it('is only a comment', fn () => 1);\n/* test('so is this') */\n\$x->test('a'); Foo::it('b'); function test(\$a) {}\n\$s = \"it('c')\";\n";
    expect(TestFileInspector::countTests($empty))->toBe(0)
        ->and(TestFileInspector::countTests("<?php\ntest('a', fn () => 1);\nit('b', fn () => 1);\n"))->toBe(2);
});

test('the collection check names a file Pest does not list', function () {
    expect(TestFileInspector::uncollected(['Unit/A.php', 'Unit/B.php'], ['Unit/A.php']))->toBe(['Unit/B.php'])
        ->and(TestFileInspector::uncollected(['Unit/A.php'], ['Unit/A.php']))->toBeEmpty();

    $listing = " INFO  Available tests:\n\n - P\\Tests\\Feature\\Auth\\AuthenticationTest::__pest_evaluable_a\n - P\\Tests\\Unit\\ExampleTest::__pest_evaluable_b\n - P\\Tests\\Unit\\ExampleTest::__pest_evaluable_c\n";
    expect(TestFileInspector::filesFromListing($listing))->toBe(['Feature/Auth/AuthenticationTest.php', 'Unit/ExampleTest.php'])
        ->and(TestFileInspector::filesFromListing('Fatal error: nothing here'))->toBeEmpty();
});
