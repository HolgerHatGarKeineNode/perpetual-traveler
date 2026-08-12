<?php

use App\Models\Event;
use App\Models\User;
use Pest\Browser\Configuration;

/*
|--------------------------------------------------------------------------
| P3 — the Kern-Interaktion, on today's v6 stand
|--------------------------------------------------------------------------
|
| Desktop drag-select: mousedown on one day cell, mousemove/drag onto
| another, mouseup -> FullCalendar's `select` handler opens the modal,
| rangeLabel() names the range, picking a country calls saveDays() through
| Livewire, and the grid re-renders the new stay as a `.ptr-stay` band
| (resources/js/nostrCal.js).
|
| actingAs() before visit() relies on the SAME thing Laravel's own feature
| tests rely on: pest-plugin-browser's LaravelHttpServer runs the fake HTTP
| server IN-PROCESS (Drivers/LaravelHttpServer::handleRequest() calls
| $kernel->handle() against the already-booted app() container), so the auth
| guard instance actingAs() populates is still the one serving the request
| the visited page makes. No login UI is exercised here; that is the
| server's own concern (tests/Feature/Auth/AuthenticationTest.php) and the
| Nostr/NIP-07 path is explicitly out of scope (needs a signer extension).
|
| The date range (16.-18. March 2026, a Monday to a Wednesday) is chosen to
| stay inside ONE week: firstDay is 1 (Monday, nostrCal.js), and FullCalendar
| slices a bar at every week boundary (documented at length next to
| `eventContent` in nostrCal.js) — a range straddling a week edge like
| 14.-16. (Sat-Mon) renders as TWO `.ptr-stay` segments with the same
| data-from/data-to, which would make a single-element attribute assertion
| ambiguous for a reason that has nothing to do with the interaction this
| test is proving. One week, one segment, one assertion.
*/

it('drag-selects two days on desktop, opens the modal, saves through Livewire and renders the stay', function () {
    (new Configuration())->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/calendar', ['viewport' => ['width' => 1280, 'height' => 800]]);

    // The grid has to exist before it can be dragged across.
    $page->assertPresent('td[data-date="2026-03-16"]')
        ->assertPresent('td[data-date="2026-03-18"]');

    // mousedown on day A, drag to day B, mouseup — Playwright's dragAndDrop
    // protocol IS exactly that sequence (hover source, mouse down, move to
    // target in steps, mouse up), not the HTML5 DnD protocol, which is what
    // FullCalendar's interaction plugin listens for.
    $page->drag('td[data-date="2026-03-16"]', 'td[data-date="2026-03-18"]');

    // The modal opened and rangeLabel() named the 3-day range. "(3 days)" is
    // locale-independent (the surrounding MM/DD/YYYY formatting is not,
    // which is why the assertion does not pin the whole string) — the
    // context is created with locale 'en-US' regardless
    // (PendingAwaitablePage::buildAwaitablePage), so this is not a guess.
    $page->assertSee('Choose country')
        ->assertSee('(3 days)');

    // Narrow the country list to one match instead of relying on click(text)
    // to disambiguate "Germany" among ~245 buttons.
    $page->fill('[aria-label="Search countries"]', 'Germany')
        ->click('button[data-country]:has-text("Germany")');

    // The modal closes, the write already round-tripped through Livewire's
    // saveDays() by the time the click() call above returns (it awaits the
    // browser action, and Livewire's own request/response cycle inside the
    // fake HTTP server is synchronous from the test's point of view).
    $page->assertDontSee('Choose country');

    // THE SERVER IS THE TRUTH — this is the same process and the same
    // sqlite :memory: connection the test itself uses (LaravelHttpServer
    // handles requests in-process), so a direct query is a stronger proof
    // of the write than reading it back off the DOM would be.
    expect(Event::where('user_id', $user->id)
        ->whereIn('day', ['2026-03-16', '2026-03-17', '2026-03-18'])
        ->pluck('country')
        ->unique()
        ->all())->toBe(['de']);

    // And the grid re-rendered the bar from the refreshed eventBars — the
    // DOM half of the same guarantee, keyed by the true (from, to) range
    // (`to` exclusive, as shipped by refreshEventBars()).
    $page->assertAttributeContains('.ptr-stay', 'aria-label', 'Germany')
        ->assertAttributeContains('.ptr-stay', 'data-from', '2026-03-16')
        ->assertAttributeContains('.ptr-stay', 'data-to', '2026-03-19');
});

/*
|--------------------------------------------------------------------------
| The computed-style sonde — a boundary, not a proof
|--------------------------------------------------------------------------
|
| This does NOT prove that a touch drag selects days on a phone: CDP-touch
| does not reliably exercise FullCalendar's touch handling (see the plan,
| "Test-Strategie"), and that gap is why mobile drag-select stays a manual
| step in P7. What this proves is narrower and cheap: the `touch-action:
| none` rule that carries the whole interaction (app.css, `@media
| (max-width: 1023px)`) has not silently disappeared. If it does, a phone
| scrolls the page under a drag instead of selecting — no error, no visible
| symptom until someone tries it by hand.
*/
it('day cells refuse touch scrolling at a mobile viewport (touch-action: none)', function () {
    (new Configuration())->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/calendar', ['viewport' => ['width' => 390, 'height' => 844]]);

    $page->assertPresent('.fc-daygrid-day');

    $touchAction = $page->script(
        "getComputedStyle(document.querySelector('.fc-daygrid-day')).touchAction"
    );

    expect($touchAction)->toBe('none');
});
