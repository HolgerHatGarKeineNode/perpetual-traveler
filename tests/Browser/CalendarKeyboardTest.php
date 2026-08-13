<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Pest\Browser\Configuration;

/*
|--------------------------------------------------------------------------
| P10 — the keyboard in the grid
|--------------------------------------------------------------------------
|
| Three findings that heal together or not at all, and the net under them:
|
|   1. there was no keyboard route to an UNSTAMPED day. Only the toolbar
|      buttons and the stay bars were focusable, so a keyboard could
|      overwrite a day but never enter one (WCAG 2.1.1).
|   2. Escape left the focus on BODY instead of on the element that opened
|      the modal (WCAG 2.4.3).
|   3. the bar cursor's position did not survive the way a user actually
|      comes back — Escape hands focus to the document, and every bar tabbed
|      past on the way in reset its own index to 0.
|
| NO MOUSE EVENT ANYWHERE IN THE FIRST TEST, which is the point of it: every
| step is a real key press through the page's keyboard (Page::keyDown/keyUp,
| i.e. CDP Input.dispatchKeyEvent), never a locator click and never a
| programmatic focus(). The later tests place focus directly where they need
| it when the walk itself is not what they are proving.
|
| WHAT THIS FILE DOES NOT ASSERT, and why it would be worthless if it did:
| that ArrowUp/ArrowDown no longer scroll the page. This harness cannot see
| scrolling at all — measured on the unchanged stand, a PageDown on a 2932px
| document with the body focused moved window.scrollY by 0. A "the page did
| not scroll" assertion would therefore be green whatever the code does. The
| observable half is asserted instead: the event is defaultPrevented and the
| cursor moves exactly one calendar week.
|
| The fixture puts both stays inside ONE calendar week (firstDay is 1), so
| each renders as a single `.fc-event` segment — the same reason
| CalendarDragSelectTest picks its range that way. A stay straddling a week
| edge is two segments with the same data-from/data-to, which would make
| "the bar" ambiguous for a reason that has nothing to do with the keyboard.
*/

function keyboardUser(): User
{
    $user = User::factory()->create(['pt_start' => '2026-01-01']);

    // 02.-06.03.2026 is Mon-Fri: one week, one segment. The plan's own
    // example range, so the figures in the findings and here are the same.
    foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', '2026-03-06'] as $day) {
        Event::create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }

    // A SECOND stay, earlier in the year, so that "tab in from the front"
    // has something to walk past. Without it, finding 3 is not reachable.
    foreach (['2026-02-02', '2026-02-03', '2026-02-04'] as $day) {
        Event::create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }

    return $user;
}

/**
 * Where the keyboard stands, as a string a test can assert on.
 *
 * The `matches` test on the element ITSELF is not pedantry — the first draft
 * of this asked `activeElement.querySelector('.ptr-stay')`, and when focus is
 * on BODY that finds the first band in the whole document. Every walk that ran
 * off the end of the tab order therefore reported "bar:2026-02-02", which is
 * exactly the reading the test below is meant to distinguish from a real visit
 * to that bar. A probe that answers plausibly for "nowhere" makes its test
 * green for free.
 */
const WHERE = <<<'JS'
(() => {
  const a = document.activeElement;
  if (!a || !a.matches) return 'none';
  if (a.matches('td[data-date]')) return 'cell:' + a.getAttribute('data-date');
  if (a.matches('.fc-event')) {
    const band = a.querySelector('.ptr-stay');
    return 'bar:' + (band ? band.dataset.from : 'unknown');
  }
  return a.tagName;
})()
JS;

/**
 * Tab until the keyboard reaches something in the grid, and report the walk.
 * Real Tab presses only — this is the thing under test in the first case.
 *
 * @return array{0: string, 1: array<int, string>}
 */
function tabUntil(object $page, string $needle, int $limit = 60): array
{
    $walk = [];

    for ($i = 0; $i < $limit; $i++) {
        $page->withKeyDown('Tab', fn () => null);
        $where = $page->script(WHERE);
        $walk[] = $where;

        if (str_starts_with($where, $needle)) {
            return [$where, $walk];
        }
    }

    return ['', $walk];
}

/*
|--------------------------------------------------------------------------
| 1 — the whole way to an unstamped day, by keyboard only
|--------------------------------------------------------------------------
|
| Tab into the grid, walk to a day that carries nothing, open it, pick a
| country, and read the day back out of the database. On the stand before
| this phase the walk ends at the second step: there is no tab stop on a day
| cell at all, so `tabUntil('cell:')` runs out and the test fails there.
|
| The target day is derived from where the cursor actually stands rather
| than hard-coded, because the cursor starts on TODAY and this test has to
| keep working tomorrow. One week down plus one day right (or up and left,
| within a fortnight of a year edge) exercises both axes on the way.
*/
it('walks from the toolbar to an unstamped day and stamps it, without a single mouse event', function () {
    new Configuration()->timeout(30_000);

    $user = keyboardUser();
    $this->actingAs($user);

    $page = visit('/calendar', ['viewport' => ['width' => 1280, 'height' => 800]]);
    $page->assertPresent('.ptr-stay');

    [$where, $walk] = tabUntil($page, 'cell:');

    expect($where)->toStartWith('cell:', 'Tab never reached a day cell: '.implode(' -> ', $walk));

    // And it took exactly ONE tab stop to get there — not one per day. 365 of
    // them would be a worse answer than none, so the count is part of the
    // property, not an incidental.
    expect($page->script("document.querySelectorAll('.fc td[data-date][tabindex]').length"))->toBe(1);

    $from = substr($where, 5);
    $year = Carbon::parse($from)->year;

    // Down a row and one to the right, unless that would leave the year the
    // grid is showing — the cursor stops at the edge of the view by design.
    $forwards = Carbon::parse($from)->addDays(8)->year === $year;
    $target = Carbon::parse($from)->addDays($forwards ? 8 : -8)->format('Y-m-d');

    expect(Event::where('user_id', $user->id)->where('day', $target)->exists())->toBeFalse();

    $page->withKeyDown($forwards ? 'ArrowDown' : 'ArrowUp', fn () => null);
    $page->withKeyDown($forwards ? 'ArrowRight' : 'ArrowLeft', fn () => null);

    expect($page->script(WHERE))->toBe('cell:'.$target);

    // The live region names the day the cursor stands on, because the mark
    // itself is visual only.
    expect($page->script("document.querySelector('[aria-live=\"polite\"]').textContent"))
        ->toContain((string) Carbon::parse($target)->day);

    $page->withKeyDown('Enter', fn () => null);
    $page->assertSee('Choose country');

    // The modal put the focus in the country search by itself, so typing
    // works without another Tab.
    expect($page->script("document.activeElement.getAttribute('aria-label')"))->toBe('Search countries');

    foreach (['G', 'e', 'r', 'm', 'a', 'n', 'y'] as $key) {
        $page->withKeyDown($key, fn () => null);
    }

    // Enter stamps the top match (the "↵ stamps top match" hint in the modal).
    $page->withKeyDown('Enter', fn () => null);
    $page->assertDontSee('Choose country');

    // THE SERVER IS THE TRUTH — same process, same in-memory connection.
    expect(Event::where('user_id', $user->id)->where('day', $target)->value('country'))->toBe('de');

    // And the grid drew the new stay.
    $page->assertPresent('td[data-date="'.$target.'"] .fc-event');

    // The modal was about a day, so the keyboard stands on that day when it
    // closes — including after a write that re-rendered the grid.
    expect($page->script(WHERE))->toBe('cell:'.$target);
});

/*
|--------------------------------------------------------------------------
| 2 — Escape gives the focus back to what opened the modal
|--------------------------------------------------------------------------
|
| Both ways in, because the defect was in the modal and not in either
| caller: on the stand before this phase, activeElement after Escape was
| BODY in both cases (measured on 6d7f51b and, with a forced rebuild, on
| 8dd30f4 — it is pre-existing, not a regression of this plan).
*/
it('puts the focus back on the element that opened the modal after Escape', function () {
    new Configuration()->timeout(30_000);

    $this->actingAs(keyboardUser());

    $page = visit('/calendar', ['viewport' => ['width' => 1280, 'height' => 800]]);
    $page->assertPresent('.ptr-stay');

    // (a) from a stay bar.
    [$where] = tabUntil($page, 'bar:2026-03-02');
    expect($where)->toBe('bar:2026-03-02');

    $page->withKeyDown('Enter', fn () => null);
    $page->assertSee('Choose country');
    expect($page->script(WHERE))->not->toBe('bar:2026-03-02');

    $page->withKeyDown('Escape', fn () => null);
    $page->assertDontSee('Choose country');

    expect($page->script(WHERE))->toBe('bar:2026-03-02');

    // (b) from a day cell.
    [$cell] = tabUntil($page, 'cell:');
    expect($cell)->toStartWith('cell:');

    $page->withKeyDown('Enter', fn () => null);
    $page->assertSee('Choose country');

    $page->withKeyDown('Escape', fn () => null);
    $page->assertDontSee('Choose country');

    expect($page->script(WHERE))->toBe($cell);
});

/*
|--------------------------------------------------------------------------
| 3 — the cursor's day survives the way back a user actually takes
|--------------------------------------------------------------------------
|
| NOT the direct return (focus the same bar again), which held before this
| phase already and would prove nothing. The real path: Escape hands the
| focus over, the user tabs in from the front, and every bar passed on the
| way used to set its own index to 0 — 03-06 came back as 03-02.
|
| The walk is asserted to have PASSED the other stay, otherwise the test
| could pass by never exercising the thing it is named after.
*/
it('keeps the cursor day of a stay across Escape and a full tab walk past the other stays', function () {
    new Configuration()->timeout(30_000);

    $this->actingAs(keyboardUser());

    $page = visit('/calendar', ['viewport' => ['width' => 1280, 'height' => 800]]);
    $page->assertPresent('.ptr-stay');

    [$where] = tabUntil($page, 'bar:2026-03-02');
    expect($where)->toBe('bar:2026-03-02');

    // Four steps right: 02.03. -> 06.03., the last day of the stay.
    for ($i = 0; $i < 4; $i++) {
        $page->withKeyDown('ArrowRight', fn () => null);
    }

    expect($page->script("window.Alpine.\$data(document.querySelector('.fc')).barCursorDay"))
        ->toBe('2026-03-06');

    $page->withKeyDown('Enter', fn () => null);
    $page->assertSee('Choose country');
    $page->assertSee('06');

    $page->withKeyDown('Escape', fn () => null);
    $page->assertDontSee('Choose country');

    // Leave the bar and come all the way round again — past the toolbar, past
    // the February stay, past the grid cursor.
    $page->withKeyDown('Tab', fn () => null);
    [$back, $walk] = tabUntil($page, 'bar:2026-03-02');

    expect($back)->toBe('bar:2026-03-02', 'never came back to the stay: '.implode(' -> ', $walk));
    expect(in_array('bar:2026-02-02', $walk, true))
        ->toBeTrue('the walk did not pass the other stay: '.implode(' -> ', $walk));

    expect($page->script("window.Alpine.\$data(document.querySelector('.fc')).barCursorDay"))
        ->toBe('2026-03-06');

    // Off the FOCUSED band, not off the first one in the document: the visible
    // mark has to be on the bar the keyboard came back to, and
    // `.fc-event .ptr-stay` would answer with February's band whatever happens
    // here.
    expect($page->script("document.activeElement.querySelector('.ptr-stay').style.getPropertyValue('--ptr-cursor')"))
        ->toBe('4');
});

/*
|--------------------------------------------------------------------------
| 4 — ArrowUp/ArrowDown are the grid's row step now
|--------------------------------------------------------------------------
|
| They used to scroll the page under the cursor, deliberately: intercepting
| them without a replacement is worse than letting them scroll. With grid
| navigation there IS a replacement, so they are taken — one calendar row,
| which is seven days in every view this app renders.
|
| The assertion is on defaultPrevented and on where the cursor lands, NOT on
| window.scrollY: this harness cannot observe scrolling (see the file header),
| so a scroll assertion would be green either way.
*/
it('takes ArrowUp and ArrowDown for the row step, from a cell and from a bar', function () {
    new Configuration()->timeout(30_000);

    $this->actingAs(keyboardUser());

    $page = visit('/calendar', ['viewport' => ['width' => 1280, 'height' => 800]]);
    $page->assertPresent('.ptr-stay');

    // Bubble phase on window, so this listener sees the flag AFTER the grid's
    // own handler has had the event.
    $page->script("window.__prevented = null; window.addEventListener('keydown', (e) => { window.__prevented = e.defaultPrevented; }); 'ok'");

    [$cell] = tabUntil($page, 'cell:');
    expect($cell)->toStartWith('cell:');
    $from = substr($cell, 5);

    $down = Carbon::parse($from)->addDays(7);
    $forwards = $down->year === Carbon::parse($from)->year;
    $target = $forwards ? $down : Carbon::parse($from)->subDays(7);

    $page->withKeyDown($forwards ? 'ArrowDown' : 'ArrowUp', fn () => null);

    expect($page->script('window.__prevented'))->toBeTrue();
    expect($page->script(WHERE))->toBe('cell:'.$target->format('Y-m-d'));

    // From a bar the same key hands over to the grid cursor at the same
    // offset — a stay has no rows of its own.
    [$bar] = tabUntil($page, 'bar:2026-03-02');
    expect($bar)->toBe('bar:2026-03-02');

    $page->withKeyDown('ArrowDown', fn () => null);

    expect($page->script('window.__prevented'))->toBeTrue();
    expect($page->script(WHERE))->toBe('cell:2026-03-09');
});

/*
|--------------------------------------------------------------------------
| 5 — the cursor is VISIBLE, and it is the size of the cell
|--------------------------------------------------------------------------
|
| WCAG 2.4.7, and the reason it is measured rather than eyeballed: the mark
| replaces the focus outline (`outline: none` on the same cell), so if the
| box fails to render there is no indicator at all — and it failed silently
| once already. Hung on `.fc-daygrid-day-frame::after` it inherited
| FullCalendar's injected clearfix (`display: table`), and an absolutely
| positioned table shrink-wraps: the box came out 4px tall in a 51,95px
| cell. Rendered, coloured, present in every style assertion one would think
| to write — and invisible.
|
| So the assertion is on the box's SIZE against the cell's, which is the
| property that broke. Both schemes, because the two inks differ (gold-600
| light / gold-400 dark, for the measured reason in app.css).
*/
it('draws the cursor as a box the size of the cell, in both colour schemes', function () {
    new Configuration()->timeout(30_000);

    $this->actingAs(keyboardUser());

    foreach (['light' => 'rgb(143, 98, 16)', 'dark' => 'rgb(239, 178, 62)'] as $scheme => $ink) {
        $page = visit('/calendar', [
            'viewport' => ['width' => 1280, 'height' => 800],
            'colorScheme' => $scheme,
        ]);
        $page->assertPresent('.fc-daygrid-day');

        // Reached by KEY press, so :focus-visible applies — a programmatic
        // focus() after a click would not show the mark, by design.
        [$cell] = tabUntil($page, 'cell:');
        expect($cell)->toStartWith('cell:');

        $mark = $page->script(<<<'JS'
(() => {
  const cell = document.activeElement;
  const s = getComputedStyle(cell, '::after');
  const r = cell.getBoundingClientRect();
  return {
    content: s.content,
    border: s.borderTopWidth + ' ' + s.borderTopStyle + ' ' + s.borderTopColor,
    zIndex: s.zIndex,
    pointerEvents: s.pointerEvents,
    outline: getComputedStyle(cell).outlineStyle,
    // The cell's padding box: its own 1px grid lines are outside it.
    coversWidth: Math.abs(parseFloat(s.width) - (r.width - 2)) < 1.5,
    coversHeight: Math.abs(parseFloat(s.height) - (r.height - 2)) < 1.5,
    height: parseFloat(s.height),
  };
})()
JS);

        expect($mark['content'])->toBe('""', $scheme);
        expect($mark['border'])->toBe('2px solid '.$ink, $scheme);
        expect($mark['zIndex'])->toBe('6', $scheme);
        // The mark must never take the pointer: the whole multi-day selection
        // rests on the cell receiving it (see nostrCal.js).
        expect($mark['pointerEvents'])->toBe('none', $scheme);
        // The mark IS the focus indicator, so the ring is deliberately off.
        expect($mark['outline'])->toBe('none', $scheme);
        expect($mark['coversWidth'])->toBeTrue($scheme.': the mark is not as wide as the cell');
        expect($mark['coversHeight'])
            ->toBeTrue($scheme.': the mark is '.$mark['height'].'px tall, the cell is not');
    }
});
