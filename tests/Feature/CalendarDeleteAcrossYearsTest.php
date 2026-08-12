<?php

use App\Models\Event;
use App\Models\User;
use Livewire\Volt\Volt;

/*
 | deleteDays() used to bound its DELETE to the year on screen, on top of the
 | whereIn over the day list it was handed. A stay that crosses New Year is ONE
 | stay in this app (App\Support\ContiguousStays, and the bar in the grid spans
 | the boundary), so a day list that crosses it is ordinary input — from the
 | modal's "Clear these days" over a selection that started in December, and from
 | pulling a cross-year bar's edge back. With the bound in place the half outside
 | the displayed year was dropped SILENTLY: no error, the modal closed, and the
 | days stayed. Measured on bda7949 against the real component, 2026-08-12:
 | deleteDays(['2027-01-01','2027-01-02']) with currentYear 2026 left all four
 | days stored.
 |
 | The year bound belongs on the RELOAD below it — the grid shows one year — not
 | on the write. The day list is the instruction, and it is already exact:
 | whereIn over explicit days, scoped to the authenticated user.
 |
 | Asserted against the DATABASE and not against $this->events, deliberately:
 | events is year-scoped by design, so a 2027 row can never appear in it and an
 | assertion there would pass whether the delete worked or not.
 */
test('deleteDays deletes days outside the displayed year, too', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'] as $day) {
        Event::create(['user_id' => $user->id, 'day' => $day, 'country' => 'de']);
    }

    Volt::test('calendar')
        ->set('currentYear', 2026)
        ->call('deleteDays', ['2027-01-01', '2027-01-02']);

    $left = Event::query()->where('user_id', $user->id)->orderBy('day')->pluck('day')
        ->map(fn ($day) => substr((string) $day, 0, 10))
        ->all();

    expect($left)->toBe(['2026-12-30', '2026-12-31']);
});

/*
 | The same delete from the other side, in ONE call that spans the boundary — the
 | shape a "Clear these days" over New Year really has. Both halves have to go;
 | with the bound only the December half did.
 */
test('deleteDays clears a day list that spans New Year in one call', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    foreach (['2026-12-29', '2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'] as $day) {
        Event::create(['user_id' => $user->id, 'day' => $day, 'country' => 'pt']);
    }

    Volt::test('calendar')
        ->set('currentYear', 2026)
        ->call('deleteDays', ['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02']);

    $left = Event::query()->where('user_id', $user->id)->orderBy('day')->pluck('day')
        ->map(fn ($day) => substr((string) $day, 0, 10))
        ->all();

    expect($left)->toBe(['2026-12-29']);
});

/*
 | THE CONTROL, and it is what makes the two above mean something: saveDays never
 | had the bound, so the two directions of one and the same day list were not
 | symmetric — a resize could write a cross-year range and then fail to take it
 | back. Kept as a test so the repair cannot be "made symmetric" by adding the
 | bound to the write path instead.
 */
test('saveDays writes days outside the displayed year, too', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('calendar')
        ->set('currentYear', 2026)
        ->call('saveDays', ['2026-12-31', '2027-01-01'], 'DE');

    $stored = Event::query()->where('user_id', $user->id)->orderBy('day')->pluck('day')
        ->map(fn ($day) => substr((string) $day, 0, 10))
        ->all();

    expect($stored)->toBe(['2026-12-31', '2027-01-01']);
});

/*
 | THE SECOND CONTROL: dropping the year bound must not turn the delete into a
 | wider one. Another user's identical day stays untouched — the user scope was
 | the real fence all along, the year bound only ever removed rows the caller
 | asked for.
 */
test('deleteDays leaves another user rows alone', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Event::create(['user_id' => $user->id, 'day' => '2027-01-01', 'country' => 'de']);
    Event::create(['user_id' => $other->id, 'day' => '2027-01-01', 'country' => 'de']);

    $this->actingAs($user);

    Volt::test('calendar')
        ->set('currentYear', 2026)
        ->call('deleteDays', ['2027-01-01']);

    expect(Event::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Event::query()->where('user_id', $other->id)->count())->toBe(1);
});
