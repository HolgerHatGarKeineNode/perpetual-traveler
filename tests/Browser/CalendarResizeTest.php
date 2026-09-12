<?php

use App\Models\User;
use Pest\Browser\Configuration;
use Tests\Browser\Support\ConsoleLatch;

/*
|--------------------------------------------------------------------------
| THE OBSERVER'S OWN JOB — still done, after the frame was put in front of it
|--------------------------------------------------------------------------
|
| resources/js/nostrCal.js observes the calendar pane and calls
| FullCalendar's updateSize() when it changes. That call used to happen
| synchronously inside the observer callback, which is what threw
| "ResizeObserver loop completed with undelivered notifications" at 1280x800;
| it now happens in the next animation frame. This file is the other half of
| that change: the message is gone AND the resize still arrives.
|
| WHY A CONTAINER-ONLY SHRINK AND NOT A VIEWPORT CHANGE. FullCalendar attaches
| its own listener to `window` resize. Resizing the viewport would therefore be
| handled with or without our observer, and a test built on it would be green
| against an empty init() — it would measure FullCalendar, not this repo. The
| pane is narrowed with an inline max-width instead, with the window untouched.
| Then the ResizeObserver is the only route to FullCalendar that exists, and
| the assertion is calibrated by construction.
|
| WHAT WAS TRIED FIRST AND THROWN AWAY, because it proves nothing: hiding and
| re-showing the pane through the Alpine tab switch. Measured at 390x844 with
| updateSize replaced by a counter, the geometry after tab -> stats -> calendar
| is IDENTICAL to the geometry before it (pane 332, day cell 47, table 330) —
| the browser recomputes a CSS table layout on its own when display:none comes
| off. That round trip would have passed against a calendar nobody resized.
|
| EACH TEST MAKES TWO CLAIMS, and both of them are calibrated:
|
|   the FIRST RENDER is correctly sized — measured by deleting the observer
|   from nostrCal.js and rebuilding. Without it, /calendar at 1280x800 lays the
|   year grid out with ONE month tile per row instead of two, and at 390x844
|   the day grid's body table is 0px wide and stays that way. That is the
|   original comment's "the pane is still zero-width at init" case, proven
|   rather than assumed: the observer is what repairs it.
|
|   a LATER container-only change still arrives — measured by leaving the
|   observer in place and replacing updateSize() with a counter after the page
|   had loaded, so that only the shrink is affected. The stale geometry that
|   produces is quoted in each test below.
|
| The two mutations are not interchangeable. Deleting the observer makes the
| first claim fail before the second one is even reached, so the counter run is
| what proves the shrink assertion itself has teeth.
|
| HOW THIS FILE RELATES TO tests/Browser/CalendarConsoleTest.php, measured in
| both directions rather than assumed — an earlier version of this paragraph
| claimed a clean split and was simply wrong one way round:
|
|   observer DELETED     these two go red, the console file stays GREEN.
|                        No observer, no loop message, nothing for the console
|                        latch to catch. This direction is exclusive: only this
|                        file guards the observer's existence.
|
|   observer SYNCHRONOUS both files go red, and both do so at 1280x800 ONLY.
|                        The console file is red on load; this file is red at
|                        the assertSilent() after the desktop shrink, which the
|                        shrink provokes twice over ("...but the page produced
|                        2 message(s): [uncaught] ResizeObserver loop ... |
|                        [uncaught] ResizeObserver loop ..."). The phone case
|                        of BOTH files stays green — that message does not
|                        occur at 390x844 under any of these mutations.
|
| The overlap in the second row is deliberate and stays: a second witness on a
| defect costs nothing, and the desktop shrink is the one moment in the suite
| that drives the observer hard enough to produce the message twice. What does
| NOT follow from it is that either file can be deleted — the first row is why.
*/

/**
 * Narrows the calendar pane itself, leaving the window alone, and lets the
 * layout settle.
 */
function shrinkPane(ConsoleLatch $latch, int $pixels): void
{
    $latch->evaluate(
        "() => { document.querySelector('[wire\\\\:ignore]').style.maxWidth = '{$pixels}px'; return true; }"
    );

    $latch->settle();
}

// The month tiles of the desktop multiMonthYear view: how many sit in the
// first row, and how wide each is.
const DESKTOP_GEOMETRY = <<<'JS'
() => {
  const pane = document.querySelector('[wire\\:ignore]');
  const months = [...document.querySelectorAll('.fc-multimonth-month')];

  return {
    pane: pane.clientWidth,
    perRow: months.filter((m) => Math.abs(m.offsetTop - months[0].offsetTop) < 2).length,
    monthWidth: Math.round(months[0].offsetWidth),
  };
}
JS;

// The phone dayGridMonth view: the width of the body table against its pane.
const PHONE_GEOMETRY = <<<'JS'
() => {
  const pane = document.querySelector('[wire\\:ignore]');
  const table = document.querySelector('.fc-daygrid-body table');

  return {
    pane: pane.clientWidth,
    tableWidth: Math.round(table.offsetWidth),
  };
}
JS;

/*
|--------------------------------------------------------------------------
| 1 — desktop: the year grid re-columns when its pane narrows
|--------------------------------------------------------------------------
|
| multiMonthYear packs as many month tiles per row as fit above
| multiMonthMinWidth (350px by default). At a pane of 856px that is two tiles
| of 428px; at 520px it is one tile of 520px. The tile COUNT per row is the
| assertion, because it is discrete — no tolerance to argue about.
|
| Measured with updateSize neutered after load, same shrink: the pane narrows
| to 520 but the view keeps two tiles per row and squeezes each to 260px, i.e.
| 90px below the minimum width the view is configured with. Measured with the
| observer deleted outright, the two-per-row starting point never happens at
| all — the grid loads at one tile per row, and the first expectation below is
| what says so.
*/
it('re-columns the desktop year grid when only the pane narrows', function () {
    (new Configuration())->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    $latch = ConsoleLatch::open('/calendar', 1280, 800);
    $latch->page->assertPresent('.fc-multimonth-month');
    $latch->settle();

    $before = $latch->evaluate(DESKTOP_GEOMETRY);

    // THE FIRST RENDER, and not just a precondition: an 856px pane fits two
    // month tiles, and it only does so because the observer corrected the size
    // FullCalendar first rendered at. With the observer deleted this is 1.
    expect($before['perRow'])->toBe(2);

    shrinkPane($latch, 520);

    $after = $latch->evaluate(DESKTOP_GEOMETRY);

    expect($after['pane'])->toBe(520)
        ->and($after['perRow'])->toBe(1)
        ->and($after['monthWidth'])->toBe(520);

    // And the repair did not buy the resize back with a noisy console.
    $latch->assertSilent('after narrowing the desktop pane to 520px');
});

/*
|--------------------------------------------------------------------------
| 2 — phone: the day grid follows its pane instead of overflowing it
|--------------------------------------------------------------------------
|
| dayGridMonth writes explicit widths into its body table, so a pane that
| narrows underneath it leaves the table at its old size. Measured with
| updateSize neutered after load, shrinking the pane from 332px to 260px: the
| table stays 330px wide — 70px of horizontal overflow inside a 260px column,
| on the viewport where horizontal overflow is least forgivable.
|
| And measured with the observer deleted outright: the table is 0px wide on
| load and never recovers. This is the phone case the observer was written for
| in the first place, and the first expectation below is the one that fails
| when it is taken away.
|
| The assertion is the table width against the pane width rather than a fixed
| 258, so it does not become a screenshot of one browser's rounding.
*/
it('keeps the phone day grid inside its pane when only the pane narrows', function () {
    (new Configuration())->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    $latch = ConsoleLatch::open('/calendar', 390, 844);
    $latch->page->assertPresent('.fc-daygrid-day');
    $latch->settle();

    $before = $latch->evaluate(PHONE_GEOMETRY);

    // THE FIRST RENDER. The grid fills its pane to begin with — which is the
    // repair, not the starting condition: with the observer deleted this is 0.
    expect($before['tableWidth'])->toBeGreaterThan($before['pane'] - 4)
        ->and($before['tableWidth'])->toBeLessThanOrEqual($before['pane']);

    shrinkPane($latch, 260);

    $after = $latch->evaluate(PHONE_GEOMETRY);

    expect($after['pane'])->toBe(260)
        ->and($after['tableWidth'])->toBeLessThanOrEqual(260)
        ->and($after['tableWidth'])->toBeGreaterThan(256);

    $latch->assertSilent('after narrowing the phone pane to 260px');
});
