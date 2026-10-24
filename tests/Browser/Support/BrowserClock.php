<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| THE BROWSER CLOCK — one fixed 2026 instant, for the page AND the server
|--------------------------------------------------------------------------
|
| WHY THIS EXISTS. The year /calendar shows follows the BROWSER's clock:
| nostrCal.js derives `currentYear` from the middle of FullCalendar's visible
| range (`mid.getFullYear()`), and FullCalendar starts on today. Every browser
| test addresses 2026 days by literal (`td[data-date="2026-03-16"]`), so the
| suite is correct for exactly as long as the machine's year is 2026.
|
| Measured 2026-10-24, not argued: a Date shim shifting the browser to
| 2027-02-01T12:00Z, prepended to the plugin's InitScript, turned 6 of the 16
| browser tests red (CalendarDragSelectTest desktop, CalendarKeyboardTest x4,
| CountrySearchTest "stamps the typed country"). The suite goes red for real
| on 2027-01-01 with no line of the app having changed.
|
| HOW IT IS PINNED. A Date replacement, installed as an init script, that
| keeps the clock RUNNING from the pinned instant: `new Date()` and Date.now()
| are the real time shifted by a constant offset, taken once per document.
| Nothing is frozen. setTimeout, requestAnimationFrame, performance.now() and
| every debounce in the page keep their real speed, which is the point: a
| frozen Date.now() would not stop timers, it would only make code that
| compares the two disagree with itself. Explicit dates (`new Date(2026, 2,
| 16)`, `new Date('2026-03-16')`, Date.UTC, Date.parse) pass through
| untouched — only the "what is today" readings move.
|
| WHERE IT HOOKS IN. visit() builds its own context and offers no hook, so
| a visit()ed page cannot be pinned. ConsoleLatch::open() builds the same kind
| of context (and a test proves that) and adds this script to it, so every
| calendar test opens its page through the latch. tests/Unit/BrowserClockGuardTest.php
| fails the suite when a collected browser test calls a bare visit().
|
| THE SERVER HALF. The in-process server reads now() too (`$this->currentYear
| ?? now()->year`, `CarbonImmutable::today()` bounding the untracked days), so
| Carbon is pinned to the same instant for every test in tests/Browser
| (tests/Pest.php). The browser would otherwise be in June and the server in
| whatever month the machine is in, and the two would agree only by luck.
| Laravel's tearDown resets Carbon after each test.
|
| ONE CONSTANT drives both halves: move INSTANT to a 2027 date and the
| literal-2026 tests go red, which is the calibration of this whole file.
|
| WHY MID-YEAR: far from both year edges, so "today" is never inside the
| week the year-boundary logic treats specially, and the keyboard test's
| "one week down" lands in the same year.
*/
final class BrowserClock
{
    public const INSTANT = '2026-06-15T12:00:00Z';

    public static function milliseconds(): int
    {
        return CarbonImmutable::parse(self::INSTANT)->getTimestamp() * 1000;
    }

    /**
     * Pins Carbon (both flavours) on the in-process server.
     */
    public static function pinServer(): void
    {
        Carbon::setTestNow(self::INSTANT);
        CarbonImmutable::setTestNow(self::INSTANT);
    }

    public static function serverIsPinned(): bool
    {
        return Carbon::hasTestNow()
            && Carbon::now()->getTimestamp() * 1000 === self::milliseconds();
    }

    /**
     * The init script. Proxy over the real Date, so `instanceof Date`,
     * Date.prototype and every static other than now() are the genuine ones.
     */
    public static function script(): string
    {
        $pinned = self::milliseconds();

        return <<<JS
        (() => {
            const RealDate = Date;
            // Taken once per document: the clock starts at the pinned instant
            // when the page starts and runs at real speed from there.
            const offset = {$pinned} - RealDate.now();

            window.Date = new Proxy(RealDate, {
                construct(target, args, newTarget) {
                    return Reflect.construct(target, args.length ? args : [RealDate.now() + offset], newTarget);
                },
                apply(target, thisArg, args) {
                    // Date() called as a function is the current time as a string.
                    return new RealDate(RealDate.now() + offset).toString();
                },
                get(target, prop, receiver) {
                    if (prop === 'now') {
                        return () => RealDate.now() + offset;
                    }

                    return Reflect.get(target, prop, target);
                },
            });
        })();
        JS;
    }
}
