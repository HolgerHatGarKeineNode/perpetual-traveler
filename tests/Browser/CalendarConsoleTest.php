<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Configuration;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Browser\Support\ConsoleLatch;

/*
|--------------------------------------------------------------------------
| THE CONSOLE ON /calendar — latched shut, as a whole
|--------------------------------------------------------------------------
|
| WHAT THIS FILE IS ANSWERING. A server-side test sees the markup; it does not
| see the browser that runs it. /calendar hands FullCalendar, Alpine and
| Livewire to that browser, and until this file existed nothing in this repo
| asserted on what the three of them said back. The page was in fact NOT
| silent: at 1280x800 it threw
|
|   "ResizeObserver loop completed with undelivered notifications."
|
| on every single load (3 of 3 measured; 0 of 3 at 390x844), and the whole
| PHP suite was green through all of it. The cause and its repair are in
| resources/js/nostrCal.js next to the observer; what is latched here is the
| CONSOLE, not that one message.
|
| IT IS A BLANKET AND NOT AN ALLOW-LIST, deliberately. An assertion that
| tolerates the messages we happened to see while writing this cannot, by
| construction, catch the next one. The latch records every console level that
| carries a complaint (six levels plus a falsy console.assert — the latch's own
| header says where that line is drawn and why), every uncaught error, every
| unhandled rejection, every failed resource and every response >= 400, and the
| assertion is that the list is empty. Measured before choosing that shape: at
| both viewports, on load and after a Livewire roundtrip, the list IS empty. No
| exception was needed, so none is granted.
|
| Read tests/Browser/Support/ConsoleLatch.php for why this does not simply use
| assertNoConsoleLogs()/assertNoJavaScriptErrors(): between them, the plugin's
| two assertions see console.log and bubbling window errors, and nothing else —
| not console.error, not a rejected promise, not a broken asset, and above all
| not an HTTP status. They are still called below, as a second and independent
| pair of eyes on the same claim.
|
| THE POSITIVE CONTROL IS THE SECOND TEST IN THIS FILE, not a note in a commit
| message. A recorder that silently stopped recording would make the first test
| pass forever; the second one injects one fault per channel and requires the
| latch to name every one of them.
*/

/*
|--------------------------------------------------------------------------
| 1 — first load AND a real roundtrip, at both viewports
|--------------------------------------------------------------------------
|
| A console that is clean on paint and dirty once the user does something is
| not clean, so the same latch is read twice. The interaction is the start-date
| field: wire:model.live.debounce, so typing a date is a genuine Livewire
| request that writes pt_start and re-derives the untracked-days strip —
| NOT a $refresh, and not a client-only Alpine toggle.
|
| WHAT SYNCHRONISES ON THAT ROUNDTRIP, and the wrong answer that was here
| first. The strip's "Untracked days" heading is rendered by the
| @if(! $ptrStart) branch (calendar.blade.php) — it is on the page BECAUSE the
| user has no start date, before any exchange with the server. An
| assertSee('Untracked') therefore returns instantly on a page nobody has
| touched, and the roundtrip ends up awaited by settle() alone, i.e. by a
| sleep: measured, the response lands at frame 11-14 of 60, so the test passes
| today and would pass silently the day the roundtrip stops happening. Proven,
| not suspected — with wire:model.live.debounce reduced to a plain wire:model
| in the blade, both viewports still passed.
|
| The signal used instead is the DISAPPEARANCE of that same branch. "Set start
| date" is the branch's button and the only occurrence of that string in the
| view; the moment the server has stored pt_start, refreshUntrackedDays() fills
| $untrackedWindow, the @if flips, and the button is gone. Nothing on the
| client can produce that. It is asserted as a transition — present before the
| fill, absent after — so that a page which never had the button could not pass
| it either. The database is then read directly, the way
| CalendarDragSelectTest reads it: same process, same in-memory connection, and
| a stronger statement about the write than the DOM can make.
|
| The two viewports are not decoration. They run DIFFERENT FullCalendar views
| (nostrCal.js picks multiMonthYear above 1023px and dayGridMonth below it),
| and the ResizeObserver message this file was written for appeared on exactly
| one of them. Both run in a Device::DESKTOP context with an explicit viewport,
| which is the house pattern of every browser test in this directory
| (visit('/calendar', ['viewport' => …]) is exactly that) — and it is the
| viewport WIDTH that selects the view, since nostrCal.js branches on
| matchMedia('(max-width: 1023px)'). Touch input is not exercised by either
| case and is not claimed to be; CalendarDragSelectTest already names that gap.
*/
it('keeps the browser console silent on /calendar, on load and through a Livewire roundtrip', function (int $width, int $height) {
    (new Configuration())->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    $latch = ConsoleLatch::open('/calendar', $width, $height);

    // The grid is rendered by FullCalendar, so its presence is the signal that
    // the client-side boot this file is measuring has actually happened. Then
    // settle: the ResizeObserver delivery and the layout write it schedules are
    // driven by the frame clock, and the message under latch is thrown at the
    // end of a delivery round, not during load.
    $latch->page->assertPresent('.fc-daygrid-day');
    $latch->settle();

    $latch->assertSilent('on first load of /calendar at '.$width.'x'.$height);

    // The plugin's own two assertions, on the same page. They cover less (see
    // the file header), but they are independent of the recorder above.
    $latch->page->assertNoJavaScriptErrors()->assertNoConsoleLogs();

    // THE ROUNDTRIP. The button is there first — without that half, "it is gone
    // afterwards" would be true of any page that never had it.
    $latch->page->assertSee('Set start date');

    $latch->page->fill('#start', '2026-01-01');

    // AWAITED, and only the server can satisfy it: the branch carrying this
    // button disappears when pt_start has been stored and the strip re-derived.
    $latch->page->assertDontSee('Set start date');

    // And the server is the truth, not the markup.
    expect($user->fresh()->pt_start?->format('Y-m-d'))->toBe('2026-01-01');

    $latch->settle();

    $latch->assertSilent('after the start-date Livewire roundtrip at '.$width.'x'.$height);
    $latch->page->assertNoJavaScriptErrors()->assertNoConsoleLogs();
})->with([
    'desktop' => [1280, 800],
    'phone' => [390, 844],
]);

/*
|--------------------------------------------------------------------------
| 2 — the positive control: every channel, proven to fire
|--------------------------------------------------------------------------
|
| A measurement that never ran looks exactly like a measurement that passed.
| So one fault is injected per channel the latch claims to cover, and the
| latch's own assertion has to go red and NAME each of them. If a wrapper is
| ever dropped, or Chrome stops delivering one of these events, this test
| fails here instead of the first test quietly passing on a dead recorder.
|
| All six console levels are injected rather than a representative one: they
| are wrapped in a loop in the recorder, and a loop is exactly the kind of
| thing that can be rewritten into a list with one member missing.
*/
it('reports every channel it latches — the positive control', function () {
    (new Configuration())->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    $latch = ConsoleLatch::open('/calendar', 1280, 800);
    $latch->page->assertPresent('.fc-daygrid-day');
    $latch->settle();

    // Whatever the page said on its own is the FIRST test's business. This one
    // starts from an empty list so that its failure text contains only the
    // faults it injected.
    $latch->assertSilent('before the positive control injects anything');

    $latch->evaluate(<<<'JS'
    () => {
        for (const level of ['error', 'warn', 'log', 'info', 'debug', 'trace']) {
            console[level]('ptr-control-' + level);
        }

        console.assert(false, 'ptr-control-assert');

        // Thrown out of a timeout, so it reaches window.onerror as an uncaught
        // error instead of rejecting this evaluate() call.
        setTimeout(() => { throw new Error('ptr-control-uncaught'); }, 0);

        Promise.reject(new Error('ptr-control-rejection'));

        // A resource that 404s. It fires 'error' ON THE ELEMENT and does not
        // bubble, which is the whole reason the recorder listens on the capture
        // phase.
        const image = document.createElement('img');
        image.src = '/ptr-control-missing-asset.png';
        document.body.appendChild(image);

        // A status code. No console channel shows this one at all.
        fetch('/ptr-control-missing-route');

        return true;
    }
    JS);

    $latch->settle(120);

    $failure = null;

    try {
        $latch->assertSilent('after the positive control injected one fault per channel');
    } catch (ExpectationFailedException $exception) {
        $failure = $exception->getMessage();
    }

    expect($failure)->not->toBeNull('The console latch stayed green while faults were on the page.');

    foreach (['error', 'warn', 'log', 'info', 'debug', 'trace'] as $level) {
        expect($failure)->toContain('[console.'.$level.'] ptr-control-'.$level);
    }

    expect($failure)
        ->toContain('[console.assert] ptr-control-assert')
        ->toContain('[uncaught] ')
        ->toContain('ptr-control-uncaught')
        ->toContain('[unhandledrejection] ')
        ->toContain('ptr-control-rejection')
        ->toContain('[resource] IMG ')
        ->toContain('ptr-control-missing-asset.png')
        ->toContain('[http] 404 ')
        ->toContain('ptr-control-missing-route');

    /*
     | AND THE OTHER WAY AN EMPTY LIST CAN LIE. The entries live on `window`, so
     | a full-page navigation drops them; a latch read after one would report
     | "no messages" about a page whose messages it threw away. This page is the
     | cheapest possible demonstration — it is already dirty, so if the guard
     | were missing the reload below would turn a red latch green, which is the
     | exact failure mode. Reloading costs one navigation on a page that is
     | finished anyway, so the guard gets its calibration for free.
     */
    $latch->reload();
    $latch->page->assertPresent('.fc-daygrid-day');

    $refusal = null;

    try {
        $latch->assertSilent('after the page navigated away from the latched document');
    } catch (ExpectationFailedException $exception) {
        $refusal = $exception->getMessage();
    }

    expect($refusal)->not->toBeNull('The latch reported on a document it had not been recording.');
    expect($refusal)->toContain('is on document #2 of this context');
});

/*
|--------------------------------------------------------------------------
| 3 — the channel that is not the console: a 500 on the roundtrip
|--------------------------------------------------------------------------
|
| A failed Livewire request is a RESOLVED promise carrying a status. It is not
| an exception, not an unhandled rejection and not a console message, so a page
| whose every interaction answers 500 passes every console assertion there is.
|
| This is the load-bearing half of that claim, and it is proven rather than
| assumed: Livewire's update endpoint is replaced with a 500 for the duration
| of this test, the same start-date interaction the first test uses is
| performed, and the latch has to name the status AND the endpoint. That it
| does proves the recorder's fetch wrapper really does sit in Livewire's
| request path — a wrapper installed on window.fetch that Livewire did not
| happen to use would prove nothing and look identical.
|
| The endpoint is resolved through its ROUTE NAME. Livewire 4 mounts it under a
| hashed prefix (livewire-<hash>/update), so a literal 'livewire/update' here
| would register a route nobody calls, the page would answer 200 as usual, and
| this test would pass while measuring nothing. It did exactly that on the
| first attempt.
|
| MEASURED WHILE WRITING THIS, and asserted below rather than worked around:
| /calendar makes a Livewire roundtrip DURING BOOT, before the user touches
| anything. FullCalendar's first datesSet() writes the year it chose into
| `currentYear`, which nostrCal.js entangles as `.live`, and the component
| mounts with that property still null — so the write is dirty and goes to the
| server. One request per load, at both viewports, on every run. The first
| draft of this test asserted the console was silent until the user acted and
| failed on exactly that, which is the test doing its job.
|
| The cheap server-side half of the same class — every roundtrip on this route
| answering 200 at all — is tests/Feature/CalendarRoundtripStatusTest.php.
*/
it('catches a 500 on the Livewire roundtrip, which no console channel shows', function () {
    (new Configuration())->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    $uri = app('router')->getRoutes()->getByName('default-livewire.update')->uri();
    Route::post($uri, fn () => response('boom', 500))->middleware('web');

    $latch = ConsoleLatch::open('/calendar', 1280, 800);
    $latch->page->assertPresent('.fc-daygrid-day');
    $latch->settle();

    // BOOT. The GET that renders the page is untouched by the override, but the
    // year the grid reports back is not — so the broken roundtrip is already on
    // the page before any user action, and the latch has it.
    $boot = $latch->drain();

    // ONE entry has to carry both the status and the endpoint. Asserting
    // against the joined messages would accept a 500 from one request and the
    // Livewire URI from another, which is precisely the pair this test exists
    // to tie together.
    $bootReports = array_values(array_filter(
        $boot,
        fn (array $entry): bool => $entry['channel'] === 'http'
            && str_contains($entry['message'], '500 ')
            && str_contains($entry['message'], $uri),
    ));

    expect($bootReports)->toHaveCount(1);

    // INTERACTION. The same start-date roundtrip the first test uses, now
    // answered with a 500, and read through the latch's own assertion so that
    // what goes red is the thing the suite actually calls.
    $latch->page->fill('#start', '2026-01-01');
    $latch->settle(120);

    $failure = null;

    try {
        $latch->assertSilent('after a Livewire roundtrip answered 500');
    } catch (ExpectationFailedException $exception) {
        $failure = $exception->getMessage();
    }

    expect($failure)->not->toBeNull('A 500 on the Livewire roundtrip left the latch green.');

    // One CONTIGUOUS entry again, not two facts from two lines of the report:
    // the channel, the status and the endpoint have to be the same message.
    expect($failure)->toMatch('#\[http\] 500 \S*'.preg_quote($uri, '#').'#');
});

/*
|--------------------------------------------------------------------------
| 4 — the latch's context is the context visit() would have opened
|--------------------------------------------------------------------------
|
| ConsoleLatch does not use visit(). It cannot: an init script only applies to
| the next document, and the latch's claim is about the FIRST one. So it builds
| its own browser context, and in doing so it restates defaults that belong to
| pest-plugin-browser — locale, timezone, colour scheme, the Device::DESKTOP
| block. The moment the plugin changes any of them, every measurement taken
| through the latch stops being comparable with every measurement taken through
| visit(), which is what the rest of tests/Browser/ uses.
|
| THE COMMENT THAT USED TO STAND IN FOR THIS TEST WAS WRONG. It claimed the
| drift would announce itself, because drain() reads window.__ptrConsole and a
| context without the init script would throw. Measured: it does not. Removing
| ...Device::DESKTOP->context() from the latch leaves the whole file green with
| EXIT=0, because __ptrConsole is installed by the latch's OWN script and is
| there whatever the plugin does with its defaults. Nothing was loud.
|
| So the drift is measured instead of asserted. The same URL is opened twice,
| once each way, and both pages are asked what they look like from the inside.
| Neither side is given the other's expected values — visit() answers out of
| the plugin's defaults, the latch answers out of its own copy of them — so any
| divergence shows up as a diff of two measured arrays. That is also why this
| test compares a page and not the option arrays: an option the plugin adds and
| the latch never heard of would be invisible in an array comparison and
| visible here, as long as it has any effect on the page at all.
|
| Its own blind spot, stated rather than left for the next reader, and stated
| at its real width: a plugin default that moves NONE of the properties the
| probe below reads would slip through. That is narrower than "no observable
| effect" — the first version of this paragraph said the latter and was wrong
| about its own test. Measured: adding a `userAgent` default to
| PendingAwaitablePage::buildAwaitablePage() in vendor left all five cases
| green (EXIT=0), although navigator.userAgent is plainly readable from inside
| the page. The probe reads it now, so that particular hole is shut; the
| general shape of the hole is not, and cannot be — the probe is a list, and a
| list is never the whole surface. Extend it when a default matters.
|
| The alternative was pinning the plugin in composer.json, declined because a
| pin holds only until someone takes the next bump — and bumps are taken here
| (Playwright moved 1.62.1 -> 1.63.0 in the session this test was written).
*/
it('opens the same kind of browser context that visit() opens', function () {
    (new Configuration())->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    // Everything a context option can change that the page can see. Read
    // through the SAME expression on both sides, so the comparison cannot
    // drift through the probe itself.
    $probe = <<<'JS'
    () => ({
        locale: navigator.language,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        prefersDark: window.matchMedia('(prefers-color-scheme: dark)').matches,
        devicePixelRatio: window.devicePixelRatio,
        maxTouchPoints: navigator.maxTouchPoints,
        userAgent: navigator.userAgent,
        innerWidth: window.innerWidth,
        innerHeight: window.innerHeight,
    })
    JS;

    $throughVisit = visit('/calendar', ['viewport' => ['width' => 1280, 'height' => 800]]);
    $throughVisit->assertPresent('.fc-daygrid-day');

    $throughLatch = ConsoleLatch::open('/calendar', 1280, 800);
    $throughLatch->page->assertPresent('.fc-daygrid-day');

    expect($throughLatch->evaluate($probe))->toBe($throughVisit->script($probe));
});
