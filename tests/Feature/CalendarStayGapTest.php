<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Volt\Volt;

// The gap between two contiguous stays of the same country is computed via
// Carbon::diffInDays() (calendar.blade.php:279). In Carbon 3 diffInDays() is
// signed (Carbon 2 returned the absolute value): the caller here is the later
// date, the argument the earlier one, so the result comes out negative. That
// negative number then also satisfies "< 21" unconditionally (:281-282), so
// every gap — however large — is shown in the risk color with a "· tight"
// label attached.
//
// Extracts the rendered <span> for the gap so both the day count, the color
// class and the presence/absence of "· tight" are asserted together — a test
// that only checked assertSee('141 days gap') could pass with the wrong
// class or an accidental "· tight" still attached.
function extractGapSpan(string $html): array
{
    preg_match(
        '/class="font-mono text-xs font-medium\s+(text-ok|text-risk)[^"]*">\s*(-?\d+) days gap( · tight)?/',
        $html,
        $matches
    );

    return [
        'class' => $matches[1] ?? null,
        'value' => isset($matches[2]) ? (int) $matches[2] : null,
        'tight' => isset($matches[3]) && $matches[3] !== '',
    ];
}

// Regression test for the exact scenario reported: two DE stays, 14.-16.03.2026
// and 05.-06.08.2026. Observed on HEAD: "-143 days gap · tight" in text-risk.
// The days strictly between 16.03. and 05.08. (17.03. through 04.08. inclusive)
// are 141 — a gap this size must not be flagged "tight" and must render ok.
test('a large gap between contiguous stays is shown positive, correct and not tight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-03-14', '2026-03-15', '2026-03-16'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }
    foreach (['2026-08-05', '2026-08-06'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }

    $html = Volt::test('calendar')->set('currentYear', 2026)->html();
    $gap = extractGapSpan($html);

    expect($gap['value'])->toBe(141)
        ->and($gap['tight'])->toBeFalse()
        ->and($gap['class'])->toBe('text-ok');
});

// A gap under the 21-day threshold must still come out positive and correctly
// counted (12 days between 03.01. and 14.01. inclusive), and must still carry
// "· tight" / text-risk — proving the threshold is genuinely discriminating
// rather than "tight" firing for every gap regardless of size (which is what
// the sign bug causes: -14 < 21 is true by accident, not because 14 is small).
test('a small gap between contiguous stays is shown positive, correct and tight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-01-01', '2026-01-02'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }
    foreach (['2026-01-15', '2026-01-16'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }

    $html = Volt::test('calendar')->set('currentYear', 2026)->html();
    $gap = extractGapSpan($html);

    expect($gap['value'])->toBe(12)
        ->and($gap['tight'])->toBeTrue()
        ->and($gap['class'])->toBe('text-risk');
});

// Smallest possible gap: two same-country stays can never be directly
// adjacent (:192 merges same-title + next-day into one stay), so the minimum
// gap is exactly one foreign day wedged between them, not zero. This is the
// off-by-one edge of the "- 1" correction in the gap formula: PT 01.-02.01.,
// DE 03.01., PT 04.-05.01. — one day (03.01.) sits strictly between the two
// PT stays, so the gap must read 1, not 0 and not 2.
test('the smallest possible gap between contiguous stays is exactly one day and still tight', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-01-01', '2026-01-02'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }
    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-01-03', 'country' => 'de']);
    foreach (['2026-01-04', '2026-01-05'] as $day) {
        Event::factory()->create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }

    $html = Volt::test('calendar')->set('currentYear', 2026)->html();
    $gap = extractGapSpan($html);

    expect($gap['value'])->toBe(1)
        ->and($gap['tight'])->toBeTrue()
        ->and($gap['class'])->toBe('text-risk');
});
