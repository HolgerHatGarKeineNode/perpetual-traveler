<?php

use Pest\Browser\Configuration;

/*
|--------------------------------------------------------------------------
| P2 wiring calibration — throwaway
|--------------------------------------------------------------------------
|
| This is NOT the P3 Drag-Select test. Its only job is to prove that the
| pest-plugin-browser -> Playwright NPM server -> Host-Chromium chain
| actually carries a request end to end: browser launches, the login page
| renders (Alpine/x-data included), and a screenshot file lands on disk.
| Delete this file once P3 exists and has established its own calibration.
|
| The 5000ms plugin default is too tight for a COLD start of the Playwright
| NPM server + Chromium launch (measured: fails ~1 in 4 fresh process runs
| at the default, timing out inside assertSee() with 0 assertions made —
| not a rendering problem, a cold-start-vs-assertion-timeout budget problem,
| the same class of trap as page.reload() timeouts in Playwright/JS). A
| generous timeout here is legitimate for a one-shot wiring proof; it is
| not a claim about acceptable interaction latency for P3.
|
*/

it('p2 wiring calibration: host chromium can render the login page and write a screenshot', function () {
    (new Configuration())->timeout(20_000);

    visit('/login')
        ->assertSee('Log in to your calendar')
        ->assertSee('Continue with Nostr key')
        ->screenshot(filename: 'p2-wiring-calibration');
});
