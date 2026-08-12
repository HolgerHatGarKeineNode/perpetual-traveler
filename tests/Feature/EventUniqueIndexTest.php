<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\QueryException;

/*
 | PINS: the unique index on (user_id, day) added in
 | 2026_08_13_001604_add_unique_index_to_events_user_id_day.php (P9 Welle A,
 | docs/plans/2026-08-10T2204-kalender-ux-tageseingabe.md). A second row for a
 | day the same user already has is what ContiguousStays' usort tie-break and
 | the "no other bar can" claim in nostrCal.js's segmentsOfBar() both assume
 | cannot exist — this is the test that makes the assumption true.
 |
 | RefreshDatabase runs every migration against the in-memory sqlite test
 | database (tests/Pest.php), so this test is red on a checkout that lacks the
 | migration and green once it is present — verified by temporarily moving the
 | migration file out of database/migrations and rerunning (see the test
 | engineer's delivery notes).
 */
test('a second row for the same user and day is rejected', function () {
    $user = User::factory()->create();

    Event::factory()->create(['user_id' => $user->id, 'day' => '2026-03-14', 'country' => 'de']);

    expect(fn () => Event::factory()->create(['user_id' => $user->id, 'day' => '2026-03-14', 'country' => 'pt']))
        ->toThrow(QueryException::class);
});

// The negative control: a DIFFERENT user may hold the same day, and the SAME
// user may hold a different day — the index is a compound one, not a hidden
// single-column constraint on either half.
test('the index does not block different users or different days', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Event::factory()->create(['user_id' => $userA->id, 'day' => '2026-03-14', 'country' => 'de']);
    Event::factory()->create(['user_id' => $userB->id, 'day' => '2026-03-14', 'country' => 'de']);
    Event::factory()->create(['user_id' => $userA->id, 'day' => '2026-03-15', 'country' => 'de']);

    expect(Event::query()->count())->toBe(3);
});
