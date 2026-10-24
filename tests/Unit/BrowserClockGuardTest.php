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
$browserTests = fn (): array => glob(dirname(__DIR__).'/Browser/*Test.php');

test('no collected browser test opens a page through a bare visit()', function () use ($browserTests) {
    $files = $browserTests();

    // Lower bound: a glob that matched nothing would make the loop below vacuous.
    expect(count($files))->toBeGreaterThanOrEqual(5);

    $offenders = [];

    foreach ($files as $file) {
        foreach (BareVisitScanner::scan(file_get_contents($file))['bare'] as $line) {
            $offenders[] = basename($file).':'.$line;
        }
    }

    expect($offenders)->toBe([], 'Bare visit() starts /calendar on the machine clock. '
        .'Open the page with ConsoleLatch::open() (it pins the clock, see BrowserClock), '
        .'or mark the line `// '.BareVisitScanner::EXEMPT_MARKER.': <reason>`: '.implode(', ', $offenders));
});

test('the only exempt visit() is the context-parity probe in CalendarConsoleTest', function () use ($browserTests) {
    $exempt = [];

    foreach ($browserTests() as $file) {
        if (BareVisitScanner::scan(file_get_contents($file))['exempt'] !== []) {
            $exempt[] = basename($file);
        }
    }

    expect($exempt)->toBe(['CalendarConsoleTest.php']);
});

// POSITIVE CONTROL. A scanner that flags nothing makes the first test pass
// forever; so the scanner is fed the shapes it has to tell apart.
test('the scanner flags a bare visit() and nothing that merely looks like one', function () {
    $scan = fn (string $php): array => BareVisitScanner::scan("<?php\n".$php);

    expect($scan("\$p = visit('/calendar');")['bare'])->toBe([2]);
    expect($scan("\$p = visit(\n'/calendar',\n);")['bare'])->toBe([2]);

    // The exemption is per line and needs the marker.
    expect($scan("\$p = visit('/calendar'); // clock-pin-exempt: probe"))
        ->toBe(['bare' => [], 'exempt' => [2]]);
    expect($scan("// clock-pin-exempt\n\$p = visit('/calendar');")['bare'])->toBe([3]);

    // Prose, strings and other callables named visit are not the global function.
    expect($scan("/* visit('/calendar') */"))->toBe(['bare' => [], 'exempt' => []]);
    expect($scan("// visit('/calendar')"))->toBe(['bare' => [], 'exempt' => []]);
    expect($scan("\$s = \"visit('/calendar')\";"))->toBe(['bare' => [], 'exempt' => []]);
    expect($scan("\$x->visit('/a'); Foo::visit('/a'); function visit(\$u) {}"))
        ->toBe(['bare' => [], 'exempt' => []]);
});
