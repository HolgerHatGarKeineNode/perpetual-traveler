<?php

use Pest\Browser\Configuration;
use Tests\Browser\Support\BrowserClock;
use Tests\Browser\Support\ConsoleLatch;

/*
 | What tests/Browser/Support/BrowserClock.php promises, read from INSIDE the
 | page. The calendar tests only show the pin indirectly (they stay green when
 | the machine's year changes); this is the direct reading, so a script that
 | stopped installing would be named here instead of surfacing on 2027-01-01.
 |
 | /faq is a guest page with no Livewire and no FullCalendar: the clock is a
 | property of the context, not of any route.
 */
it('runs the page clock from the pinned instant, keeps it moving, and leaves explicit dates alone', function () {
    new Configuration()->timeout(15_000);

    $latch = ConsoleLatch::open('/faq', 1280, 800);
    $latch->page->assertSee('Questions on arrival');

    $pinned = BrowserClock::milliseconds();

    $reading = (array) $latch->evaluate(<<<'JS'
    () => {
        const first = Date.now();

        return new Promise((resolve) => {
            let n = 0;
            const step = () => (++n < 30) ? requestAnimationFrame(step) : resolve({
                first: first,
                second: Date.now(),
                viaConstructor: new Date().getTime(),
                year: new Date().getFullYear(),
                isDate: new Date() instanceof Date,
                asFunction: Date(),
                explicitLocal: new Date(2031, 2, 16).getDate(),
                explicitIso: new Date('2031-01-01T00:00:00Z').getUTCFullYear(),
                utc: Date.UTC(2030, 0, 1),
            });
            requestAnimationFrame(step);
        });
    }
    JS);

    // Pinned: within a minute of the instant (the page has been alive for a
    // few seconds at most), nowhere near the machine's own clock.
    expect($reading['first'])->toBeGreaterThanOrEqual($pinned)->toBeLessThan($pinned + 60_000);
    expect($reading['year'])->toBe(2026)
        ->and($reading['asFunction'])->toContain('2026');

    // Running, not frozen: 30 frames later the clock has moved, at real speed.
    expect($reading['second'])->toBeGreaterThan($reading['first'])->toBeLessThan($reading['first'] + 10_000);
    expect($reading['viaConstructor'])->toBeGreaterThanOrEqual($reading['second']);

    // Still the real Date underneath.
    expect($reading['isDate'])->toBeTrue();
    expect($reading)->toMatchArray(['explicitLocal' => 16, 'explicitIso' => 2031, 'utc' => 1_893_456_000_000]);

    // The other half: the in-process server reads the same instant.
    expect(BrowserClock::serverIsPinned())->toBeTrue();
    expect(now()->year)->toBe(2026);

    $latch->assertSilent('on /faq');
});
