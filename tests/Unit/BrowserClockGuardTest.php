<?php

use Tests\Support\BareVisitScanner;

/*
 | THE LOCK ON tests/Browser/Support/BrowserClock.php.
 |
 | Pinning the page's clock only works for pages opened through
 | ConsoleLatch::open(); visit() builds its own context and has no hook. A new
 | browser test that reaches for visit() out of habit therefore opens /calendar
 | on the MACHINE's clock and is correct until the first day of the next year —
 | no test goes red when it is written, which is exactly why this scan exists.
 |
 | The one legitimate bare visit() is the parity case in CalendarConsoleTest,
 | which must open the page THE PLUGIN'S way to compare it with the latch's
 | context. It carries the `clock-pin-exempt` marker on its line and is listed
 | below by name, so a second exemption has to be argued for in this file.
 |
 | Only *Test.php is scanned: that is what phpunit.xml collects. ReferenceCapture.php
 | is run by hand (composer browser:capture-references) against a 2024 fixture
 | it navigates to explicitly.
 */

/** Browser test files that phpunit.xml collects. */
$browserTests = fn (): array => BareVisitScanner::testFilesUnder(dirname(__DIR__).'/Browser');

$relative = fn (string $file): string => substr($file, strlen(dirname(__DIR__).'/Browser/'));

test('no collected browser test opens a page through a bare visit()', function () use ($browserTests, $relative) {
    $files = $browserTests();

    // Lower bound: a glob that matched nothing would make the loop below vacuous.
    expect(count($files))->toBeGreaterThanOrEqual(5);

    $offenders = [];

    foreach ($files as $file) {
        foreach (BareVisitScanner::scan(file_get_contents($file))['bare'] as $line) {
            $offenders[] = $relative($file).':'.$line;
        }
    }

    expect($offenders)->toBe([], 'Bare visit() starts /calendar on the machine clock. '
        .'Open the page with ConsoleLatch::open() (it pins the clock, see BrowserClock), '
        .'or mark the line `// '.BareVisitScanner::EXEMPT_MARKER.': <reason>`: '.implode(', ', $offenders));
});

test('the only exempt visit() is the context-parity probe in CalendarConsoleTest', function () use ($browserTests, $relative) {
    $exempt = [];

    foreach ($browserTests() as $file) {
        if (BareVisitScanner::scan(file_get_contents($file))['exempt'] !== []) {
            $exempt[] = $relative($file);
        }
    }

    expect($exempt)->toBe(['CalendarConsoleTest.php']);
});

// POSITIVE CONTROL. A scanner that flags nothing makes the first test pass
// forever; so the scanner is fed the shapes it has to tell apart.
test('the scanner flags a bare visit() and nothing that merely looks like one', function () {
    $scan = fn (string $php): array => BareVisitScanner::scan("<?php\n".$php);

    expect($scan("\$p = visit('/calendar');")['bare'])->toBe([2])
        ->and($scan("\$p = visit(\n'/calendar',\n);")['bare'])->toBe([2]);

    // The exemption is per line and needs the marker.
    expect($scan("\$p = visit('/calendar'); // clock-pin-exempt: probe"))
        ->toBe(['bare' => [], 'exempt' => [2]]);
    expect($scan("// clock-pin-exempt\n\$p = visit('/calendar');")['bare'])->toBe([3]);

    // Prose, strings and other callables named visit are not the global function.
    expect($scan("/* visit('/calendar') */"))->toBe(['bare' => [], 'exempt' => []]);
    expect($scan("// visit('/calendar')"))->toBe(['bare' => [], 'exempt' => []])
        ->and($scan("\$s = \"visit('/calendar')\";"))->toBe(['bare' => [], 'exempt' => []])
        ->and($scan("\$x->visit('/a'); Foo::visit('/a'); function visit(\$u) {}"))->toBe(['bare' => [], 'exempt' => []]);
});

// Namespaced spellings. PHP 8 makes `\visit` and `\Pest\Browser\visit` ONE name
// token (T_NAME_FULLY_QUALIFIED), which a check for T_STRING never sees: the
// scanner returned bare => [] for all of them (reviewer repro, 2026-10-24).
test('the scanner sees visit() however it is spelled', function () {
    $scan = fn (string $php): array => BareVisitScanner::scan("<?php\n".$php);

    expect($scan("\$p = \\visit('/calendar');")['bare'])->toBe([2])
        ->and($scan("\$p = \\Pest\\Browser\\visit('/calendar');")['bare'])->toBe([2])
        ->and($scan("\$p = Browser\\visit('/calendar');")['bare'])->toBe([2])
        ->and($scan("use function Pest\\Browser\\visit as open;\n\$p = open('/calendar');")['bare'])->toBe([3])
        ->and($scan("\$p = \\visit('/calendar'); // clock-pin-exempt: probe"))->toBe(['bare' => [], 'exempt' => [2]]);

    // The import itself, a method and a class constant of that name are not calls.
    expect($scan("use function Pest\\Browser\\visit;\n\$a->visit('/x'); Foo::visit('/x'); new Visit('/x');"))
        ->toBe(['bare' => [], 'exempt' => []]);
});

// phpunit.xml collects tests/Browser RECURSIVELY; the file list must too.
test('the file list reaches tests in subdirectories', function () {
    $root = sys_get_temp_dir().'/ptr-visit-guard-'.bin2hex(random_bytes(4));
    mkdir($root.'/Sub/Deeper', 0777, true);
    file_put_contents($root.'/TopTest.php', "<?php\n");
    file_put_contents($root.'/Sub/NestedTest.php', "<?php\n\$p = visit('/calendar');\n");
    file_put_contents($root.'/Sub/Deeper/DeepTest.php', "<?php\n\$p = \\visit('/calendar');\n");
    file_put_contents($root.'/Sub/Helper.php', "<?php\n\$p = visit('/calendar');\n");

    try {
        $files = BareVisitScanner::testFilesUnder($root);

        expect(array_map(fn (string $f) => substr($f, strlen($root) + 1), $files))
            ->toBe(['Sub/Deeper/DeepTest.php', 'Sub/NestedTest.php', 'TopTest.php']);

        $offenders = array_filter($files, fn (string $f) => BareVisitScanner::scan(file_get_contents($f))['bare'] !== []);
        expect($offenders)->toHaveCount(2);
    } finally {
        array_map('unlink', glob($root.'/Sub/Deeper/*'));
        array_map('unlink', glob($root.'/Sub/*.php'));
        array_map('unlink', glob($root.'/*.php'));
        rmdir($root.'/Sub/Deeper');
        rmdir($root.'/Sub');
        rmdir($root);
    }
});
