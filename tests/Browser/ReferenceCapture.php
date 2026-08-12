<?php

use App\Models\Event;
use App\Models\User;
use Pest\Browser\Configuration;

/*
|--------------------------------------------------------------------------
| P4 -- the capture logic behind tests/Browser/References/*.png
|--------------------------------------------------------------------------
|
| NOT named "*Test.php" ON PURPOSE, and that is the whole mechanism that
| keeps it out of `php artisan test`: phpunit.xml's <testsuite name="Browser">
| has no `suffix` attribute on its <directory>, and PHPUnit's schema default
| for that attribute is "Test.php" (vendor/phpunit/phpunit/phpunit.xsd:319,
| Xml/Loader.php:1416) -- the suite scan simply never looks at a file that
| doesn't end that way, whether it sits in this directory or not. Bare
| `vendor/bin/pest` (no path argument) uses the same phpunit.xml suite
| discovery, so it is equally blind to this file. Verified empirically
| below, not just reasoned from the docs.
|
| Only an EXPLICIT invocation reaches it, by path:
|   composer browser:capture-references
| which is `vendor/bin/pest tests/Browser/ReferenceCapture.php --no-tia`
| (explicit-path invocation bypasses suite/suffix filtering entirely --
| the same route P2WiringCalibrationTest.php used before phpunit.xml ever
| named it in P3).
|
| Running it alone reproduces the four PNGs into tests/Browser/Screenshots/
| (the plugin's own, already-gitignored output directory) and goes NO
| FURTHER -- tests/Browser/References/ is untouched by default. Overwriting
| the four tracked reference files is a second, deliberate act, gated on an
| environment variable rather than being what running this file does:
|
|   PTR_WRITE_REFERENCES=1 composer browser:capture-references
|
| That is the "bewusste Handlung" the request asks for: the default run is
| safe to fire at any time (e.g. to reproduce and hash-compare against the
| tracked files without risk), and the destructive path needs a name typed
| out, not a flag that is easy to leave on a command line by habit.
|
| FIXTURE, VIEWPORTS, COLOR SCHEME, NAVIGATION AND THE FONT WAIT are
| unchanged from the version that produced the four committed hashes -- see
| the report for the byte-identical re-run.
*/

beforeEach(function () {
    (new Configuration())->timeout(20_000);

    $this->user = User::factory()->create([
        'name' => 'Reference Traveller',
        'email' => 'reference-traveller@example.test',
    ]);

    // Multiple countries, and one contiguous multi-day stay (Germany,
    // 10.-16.06.2024) -- the two things the DoD asks the fixture to carry.
    // The German run is also the exact month the mobile (day-grid) reference
    // is pinned to, so both the year view and the month view show it.
    //
    // FIXTURE YEAR IS HARDCODED TO 2024 AND STAYS THAT WAY ON PURPOSE.
    // FullCalendar marks the real, wall-clock "today" with .fc-day-today
    // (app.css:1075, :1245 -- gold fill). nostrCal.js sets no `initialDate`,
    // so a fresh Calendar always opens on the browser's actual current
    // date; only after that does `datesSet` sync `currentYear` back to
    // Livewire. A calendar year that happened to be THIS year would carry a
    // highlighted cell that moves every single day the wall clock advances
    // -- the fixture would diff itself, one level up from random event
    // data. 2024 is already in the past and can never become "today"
    // again, so the grid this file navigates to (via Calendar.gotoDate(),
    // not the prev/next buttons -- no click-count arithmetic relative to
    // "today" needed) never contains that cell, this run or any future one.
    $rows = collect(range(10, 16))
        ->map(fn ($d) => ['day' => sprintf('2024-06-%02d', $d), 'country' => 'de'])
        ->push(['day' => '2024-01-05', 'country' => 'fr'])
        ->merge(collect(range(2, 4))->map(fn ($d) => ['day' => sprintf('2024-09-%02d', $d), 'country' => 'it']))
        ->push(['day' => '2024-12-24', 'country' => 'es']);

    foreach ($rows as $row) {
        Event::create(['user_id' => $this->user->id, 'day' => $row['day'], 'country' => $row['country']]);
    }

    $this->actingAs($this->user);
});

/*
| Jumps the already-rendered calendar straight to a month of the fixture
| year via the FullCalendar instance's own gotoDate() API, reached through
| Alpine's internal `_x_dataStack` (the "Alpine $data introspection"
| technique). Deliberately not prev()/next() clicks: gotoDate() needs no
| click-count arithmetic relative to the real "today" and cannot drift as
| the wall clock advances.
|
| `td[data-date]` is asserted present FIRST -- it only exists in the DOM
| after nostrCal.js has run `this.calendar = new Calendar(...)` AND
| `.render()`, in that order, so by the time this selector resolves the
| `calendar` property on the Alpine scope is guaranteed to be set.
*/
function ptrGotoFixtureMonth($page, int $year, int $month): void
{
    $page->assertPresent('td[data-date]');

    $page->script(sprintf(
        'document.querySelector(\'[x-ref="cal"]\').closest(\'[x-data]\')' .
        '._x_dataStack[0].calendar.gotoDate(new Date(%d, %d, 1));',
        $year,
        $month - 1
    ));
}

/*
| Waits out the web-font download (fonts.bunny.net, app.blade.php) before a
| screenshot is taken. This is a THEORETICAL source of non-determinism, not
| a measured one: removing this call was tried (3 runs, no font wait) and
| every hash stayed identical to the waited version -- in this sandbox the
| Livewire round-trip that gotoDate() triggers already burns more wall time
| than the WOFF2 fetch needs, so the race never actually opened. Kept
| anyway, because that outcome is an accident of this network's latency,
| not a property of the page: a slower connection (or a warm vs. cold
| browser font cache) could still let the screenshot fire mid-swap. Cheap
| and provably harmless (`document.fonts.ready` never rejects), so there is
| no reason to trade the guarantee away for a measurement that happened not
| to catch it today.
*/
function ptrWaitForFonts($page): void
{
    $page->script('document.fonts.ready.then(() => true)');
}

/*
| The one place that ever writes into tests/Browser/References/. Gated on
| PTR_WRITE_REFERENCES so that running this file (or `composer
| browser:capture-references` with no flag) can never touch the tracked
| files by accident -- overwriting them is a second, explicit choice.
*/
function ptrPromoteToReference(string $capturedFilename, string $referenceName): void
{
    if (getenv('PTR_WRITE_REFERENCES') !== '1') {
        return;
    }

    $source = __DIR__.'/Screenshots/'.$capturedFilename.'.png';
    $target = __DIR__.'/References/'.$referenceName.'.png';

    if (! is_file($source)) {
        throw new RuntimeException("Captured screenshot missing at {$source}, refusing to touch {$target}.");
    }

    copy($source, $target);
}

it('captures the desktop light reference', function () {
    $page = visit('/calendar', [
        'viewport' => ['width' => 1280, 'height' => 800],
        'colorScheme' => 'light',
    ]);

    ptrGotoFixtureMonth($page, 2024, 1); // multiMonthYear shows the whole year regardless of month
    $page->assertPresent('.ptr-stay[data-from="2024-06-10"]');
    ptrWaitForFonts($page);

    $page->screenshot(true, 'p4-desktop-light');
    ptrPromoteToReference('p4-desktop-light', 'desktop-light');
});

it('captures the desktop dark reference', function () {
    $page = visit('/calendar', [
        'viewport' => ['width' => 1280, 'height' => 800],
        'colorScheme' => 'dark',
    ]);

    ptrGotoFixtureMonth($page, 2024, 1);
    $page->assertPresent('.ptr-stay[data-from="2024-06-10"]');
    ptrWaitForFonts($page);

    $page->screenshot(true, 'p4-desktop-dark');
    ptrPromoteToReference('p4-desktop-dark', 'desktop-dark');
});

it('captures the mobile light reference', function () {
    $page = visit('/calendar', [
        'viewport' => ['width' => 390, 'height' => 844],
        'colorScheme' => 'light',
    ]);

    ptrGotoFixtureMonth($page, 2024, 6); // dayGridMonth -- one month, the German stay's own
    $page->assertPresent('.ptr-stay[data-from="2024-06-10"]');
    ptrWaitForFonts($page);

    $page->screenshot(true, 'p4-mobile-light');
    ptrPromoteToReference('p4-mobile-light', 'mobile-light');
});

it('captures the mobile dark reference', function () {
    $page = visit('/calendar', [
        'viewport' => ['width' => 390, 'height' => 844],
        'colorScheme' => 'dark',
    ]);

    ptrGotoFixtureMonth($page, 2024, 6);
    $page->assertPresent('.ptr-stay[data-from="2024-06-10"]');
    ptrWaitForFonts($page);

    $page->screenshot(true, 'p4-mobile-dark');
    ptrPromoteToReference('p4-mobile-dark', 'mobile-dark');
});
