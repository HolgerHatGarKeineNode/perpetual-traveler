<?php

use function Laravel\Folio\{middleware};
use function Livewire\Volt\{computed, state, mount, protect, updated, rules};
use App\Models\Event;
use App\Support\ContiguousStays;
use Carbon\CarbonImmutable;

middleware(['auth']);

state(['events', 'eventBars', 'currentYear', 'start', 'selectedCountries', 'untrackedDays', 'untrackedWindow']);

rules([
    'start' => 'required|date',
]);

$countries = computed(function () {
    return collect(countries())->sortBy('name');
});

/*
 | THE ONE DERIVATION ON THIS PAGE THAT IS NOT BOUND TO THE YEAR ON SCREEN.
 |
 | A contiguous stay is a fact about the user's history: 28.12.2025-05.01.2026 is
 | nine days whichever year you look at it from. Every other figure here IS a
 | statement about the calendar year (days total, the PT split, the modal chips,
 | the untracked days) and stays year-bound — for residency, days are counted per
 | year. Both live side by side on purpose; the run carries a line naming its
 | share of the year so the two numbers cannot read as a contradiction.
 |
 | WHY computed() AND NOT A state() PROPERTY: $events is entangled with Alpine,
 | so anything put in state() is serialised to the client on every request. The
 | calendar grid needs the displayed year and nothing else, while this needs the
 | whole history — one row per day, ~11 000 rows for 30 years. computed() keeps
 | it server-side and memoises it for the request.
 |
 | WHY THE WHOLE HISTORY AND NOT A WALK OUTWARDS FROM THE YEAR EDGES: measured,
 | not assumed. Fixture of one row per day, five countries, stays of ~18 days;
 | medians of 5-7 renders on SQLite, base commit 11bc357 measured with the same
 | probe:
 |
 |   1 826 rows (5 years)   render 22,6 -> 25,9 ms   query+map+derive  1,9 ms
 |  10 958 rows (30 years)  render 26,4 -> 53,8 ms   query+map+derive 13-23 ms
 |
 | 30 years of UNBROKEN daily records is the ceiling of this data model, and it
 | costs ~27 ms once per render. The narrower variant cannot do better than
 | linear in the worst case anyway — a run may be 30 years long, and its full
 | length is exactly what has to be printed — so it would buy milliseconds in the
 | common case for a second notion of "adjacent" that can disagree with this one.
 | That disagreement is the bug this phase is fixing.
 |
 | toBase(): the rows are two scalars each and are thrown away immediately, so
 | Eloquent hydration is pure overhead — 145,7 ms against 13,4 ms at 10 958 rows,
 | same run.
 |
 | The country title is memoised per ISO code, because country() reads the rinvex
 | dataset: once per DAY instead of once per COUNTRY costs 105,8 ms against
 | 13,4 ms at the same size.
 */
$contiguousStays = computed(function () {
    // $currentYear is a public Livewire property, so the client sets it. Clamped
    // exactly like refreshUntrackedDays() above, so that the seam line, the day
    // share it prints and that function all speak about ONE year.
    // No longer load-bearing against a crash, and the comment says so rather
    // than implying it: ContiguousStays does integer calendar arithmetic and has
    // no year PHP's DateTime would refuse. It WAS load-bearing while the year
    // bounds went through a date object — the pre-existing "absurd
    // client-supplied year" test drives -5 through here, and DateTimeImmutable
    // rejected '-005-01-01' with "Double timezone specification".
    $year = max(1970, min(9999, (int) ($this->currentYear ?? now()->year)));
    $titles = [];

    // ->get()->map() and not ->cursor() with a generator: the lazy variant saves
    // ~8 MB of peak process memory (110 -> 102 MB in the probe) but costs ~11 ms
    // in query+map+derive at 10 958 rows (27,7 -> 39,2 ms measured), and the peak
    // is not what hurts here.
    $days = Event::query()
        ->where('user_id', auth()->id())
        ->orderBy('day')
        ->toBase()
        ->get(['day', 'country'])
        ->map(function ($row) use (&$titles) {
            $titles[$row->country] ??= country($row->country)->getEmoji() . ' ' . country($row->country)->getName();

            return ['title' => $titles[$row->country], 'day' => $row->day];
        });

    return ContiguousStays::intersectingYear($days, $year);
});

/*
 | A missing day is not "zero days in a country" — it is UNKNOWN, and it drags
 | the year total down without saying so. This builds the one list of those
 | days for the year in view. It is the SINGLE source for both carriers: the
 | strip above the calendar prints count($untrackedDays), and the grid hatches
 | exactly the members of $untrackedDays (nostrCal.js -> dayCellClassNames does
 | set lookups only, no date arithmetic of its own). Two separate computations
 | could drift apart; one list cannot.
 |
 | Also the only server-side piece here, which is the point: a Pest test can
 | pin the number. Client-side it would be unverifiable in this repo (no
 | Vitest, no Playwright, no CI).
 */
$refreshUntrackedDays = protect(function () {
    // $currentYear is a public Livewire property, so the client sets it. Clamped
    // so CarbonImmutable::create() can never be handed a year PHP's DateTime
    // refuses. The day loop further down needs no separate guard: both of its
    // bounds are clamped INTO this year, so it cannot run more than 366 times
    // however old the start date is.
    $year = max(1970, min(9999, (int) ($this->currentYear ?? now()->year)));

    /*
     | LOWER BOUND — the perpetual-traveler start date. From that day on, days
     | count towards residency, so from that day on a blank day is a real hole
     | in the record. Earlier days are "counted apart" (the field says so
     | itself), so their absence is not a hole in the residency record.
     |
     | No start date set: there is no lower bound, so nothing is marked and the
     | strip asks for the date instead. Falling back to "first tracked day"
     | was rejected — it would make the marked range depend on the very data
     | it criticises: adding a single day in January would conjure weeks of
     | fresh hatching out of nowhere. A signal whose extent moves with the
     | data it judges is not a measurement.
     */
    // blank() first, because CarbonImmutable::parse('') quietly returns NOW --
    // an empty field would otherwise mark a window it never had. That branch is
    // reachable: clearing the date input sends ''.
    // rescue() second, and it is defence in depth, NOT load-bearing today: an
    // unparseable $start already throws one frame earlier, in the pre-existing
    // updated('start') hook (Carbon::parse there, HEAD line 109, untouched by
    // this phase). Kept so that hardening that hook cannot silently turn this
    // function into the next crash site.
    $startDay = blank($this->start)
        ? null
        : rescue(fn () => CarbonImmutable::parse($this->start)->startOfDay(), null, false);

    // $untrackedWindow is the view's ONLY input for this strip, so that the
    // template re-derives nothing and cannot drift from what was computed here:
    //   null                          -> no usable start date at all
    //   ['start' => …, 'from' => null] -> start usable, but this year has no
    //                                     checkable window (wholly before the
    //                                     start date, or wholly ahead)
    //   ['start','from','to']         -> the checked window
    if (! $startDay) {
        $this->untrackedDays = [];
        $this->untrackedWindow = null;

        return;
    }

    $from = $startDay->max(CarbonImmutable::create($year, 1, 1)->startOfDay());

    /*
     | UPPER BOUND — the last day that is OVER. A day still running cannot be a
     | gap yet: you have not "been" anywhere for a day that has not finished.
     | That is why it is yesterday and not today, and it has a second, welcome
     | consequence: the app stores no per-user timezone and runs on UTC
     | (config/app.php), while the calendar renders in the browser's local
     | timezone. A client's local date is never earlier than UTC's date minus
     | one, so an upper bound of "UTC yesterday" can never reach into a day the
     | browser still considers the future — the failure this phase exists to
     | avoid. Anchoring on today would put one future cell under the hatch for
     | every user west of UTC, for part of every day.
     | For a year that is already over, 31 Dec is the earlier bound.
     */
    $to = CarbonImmutable::create($year, 12, 31)->startOfDay()
        ->min(CarbonImmutable::today()->subDay());

    if ($from->greaterThan($to)) {
        // The year lies wholly before the start date, or wholly in the future.
        $this->untrackedDays = [];
        $this->untrackedWindow = ['start' => $startDay->format('Y-m-d'), 'from' => null, 'to' => null];

        return;
    }

    $tracked = collect($this->events)
        ->map(fn ($event) => CarbonImmutable::parse($event['start'])->format('Y-m-d'))
        ->flip();

    $untracked = [];

    for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
        $key = $day->format('Y-m-d');

        if (! $tracked->has($key)) {
            $untracked[] = $key;
        }
    }

    $this->untrackedDays = $untracked;
    $this->untrackedWindow = [
        'start' => $startDay->format('Y-m-d'),
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
    ];
});

/*
 | The "Recently used" chips. Extracted from mount() because P3 puts a NUMBER
 | on every chip, and that number is a statement about the year on screen:
 | "Portugal, 148 days in 2026 now, 157 after stamping". A chip list frozen at
 | mount would carry last year's countries into this year's readout, and a
 | country the user has just stamped would never get a chip at all — so the one
 | figure that matters would never appear where the decision is made. The chips
 | themselves (order, look, behaviour) are untouched; only their input is kept
 | current. ->values() so the JSON stays an array when the first occurrences of
 | the codes are not adjacent.
 */
$refreshRecentCountries = protect(function () {
    $this->selectedCountries = collect($this->events)->pluck('country')->unique()->values()->toArray();
});

/*
 | THE BAR PROJECTION — the grid's event source. One entry per contiguous stay
 | that touches the displayed year, so a 30-day stay is ONE bar instead of 30
 | chips: the run reads as one journey, the country NAME finds room inside it,
 | and a cut segment can say that it comes from or goes somewhere else.
 |
 | THE DATA MODEL DOES NOT MOVE. One row per day stays the atomic unit — the
 | residency rules count days — and $this->events stays day-wise and untouched
 | beside this. Every counting path reads it: the modal's preview getters build
 | their day->country map from it (resources/js/nostrCal.js), so do the docket,
 | the chips and the stats pane. The bars are an ADDITIONAL channel; replacing
 | `events` with ranges would make nearly every day in the docket read "no
 | country".
 |
 | ONE DERIVATION, and literally one: the runs are read off $this->contiguousStays
 | — the same computed property the stays panel prints, memoised for the request,
 | so the bars in the grid and the runs in the panel are not two derivations that
 | agree, they are one array read twice. Measured rather than assumed: ONE
 | history query per request, in the mount request and in an update request alike
 | (counted through DB::listen, with a deliberate extra query as the control that
 | the counter reacts at all). A second grouping in JavaScript is what this
 | avoids; the derivation itself lives in App\Support\ContiguousStays.
 | Ordering: this runs from the four places that refresh $this->events, i.e.
 | always BEFORE the render, so it is also the first touch of that memo — the
 | year it counts for is the year the view then labels it with. That is what
 | case 5 of tests/Feature/CalendarStayBarsTest.php pins from the other side:
 | switching the year to 2025 must drop the run that only touches 2026, which a
 | memo left over from the previous year would not do.
 |
 | WHY state() AND NOT computed(), against the reasoning on $contiguousStays
 | above: only an entangled state() property reaches Alpine, and this payload is
 | for the client. The size objection does not apply here — $contiguousStays is
 | kept off the wire because it is derived from the WHOLE history (~11 000 rows
 | for 30 years), while the bars are only the runs that intersect one year: at
 | most 366 of them (a different country every day), i.e. the order of magnitude
 | of `events`, which is already shipped.
 |
 | THE COUNTRY COMES OUT OF THE DAY-WISE CHANNEL, not out of a second country()
 | lookup: ContiguousStays keys its runs by TITLE, and $this->events already
 | carries the (title, country) pair for every tracked day of the year. So the
 | two channels cannot spell a country differently — there is only one spelling.
 |
 | `?? null` IS REACHABLE, and the case is measured rather than imagined. The
 | column is dateTime('day'), so a row can hold '2026-12-31 00:00:00'. The year
 | query compares strings — where('day', '<=', '2026-12-31') — and excludes it,
 | while ContiguousStays::ordinal() accepts it (its pattern allows a time part).
 | Reproduced: one such row yields events = [] and one bar whose country is null.
 | Unreachable through saveDays(), which writes the 'Y-m-d' strings the day list
 | is built from, but reachable through any other writer.
 | Rendering the bar without its code is the deliberate direction: the stay is a
 | fact, and hiding it would be a wrong statement about where the traveller was,
 | while a missing two-letter code costs a label the name already carries.
 */
$refreshEventBars = protect(function () {
    /*
     | Drop the memo before reading it, so this always derives from the CURRENT
     | database and the CURRENT year rather than from whatever an earlier step of
     | the same request happened to cache.
     |
     | The hole it closes: Livewire applies `updates` before `calls`, so a
     | currentYear sync and a saveDays can land in ONE request. The year hook would
     | then fill the memo BEFORE the write, and the second refresh would project a
     | database state that no longer exists — the grid would sit one save behind
     | `events`, and the two channels are meant to be incapable of disagreeing.
     | Not reachable from the UI as it stands (the reviewer tried); closed anyway,
     | because an unreachable path that shows a wrong DATE has cost this plan two
     | phases already, and the fix is one line.
     |
     | Measured, not reasoned from the docs. That `unset()` on a Volt computed
     | property really invalidates it: with the memo demonstrably warm (two
     | consecutive reads cost 0 history queries each), the read after an unset()
     | costs exactly 1 — so the cache was active and the unset cleared it. The
     | mechanism is BaseComputed::handleMagicUnset() dropping the same
     | $requestCachedValue that the getter fills
     | (vendor/livewire/livewire/src/Features/SupportComputed/BaseComputed.php).
     | And it costs nothing here, because at this point the memo is empty anyway:
     | still ONE history query per request, in the mount request and in an update
     | request alike, before and after this line.
     */
    unset($this->contiguousStays);

    $codeByTitle = collect($this->events)->pluck('country', 'title');

    $bars = [];

    foreach ($this->contiguousStays as $title => $runs) {
        foreach ($runs as $run) {
            $bars[] = [
                'title' => $title,
                'country' => $codeByTitle->get($title),
                // The TRUE range, never clipped to the displayed year: a bar
                // that starts in December renders its visible part in the
                // January grid and reports fc-event-start = false at the cut,
                // which is what lets the segment say "this comes from before".
                'start' => $run['from'],
                // THE ONE +1 IN THE WHOLE PIPELINE. ContiguousStays reports `to`
                // INCLUSIVE, FullCalendar reads `end` EXCLUSIVE, so the shift
                // happens exactly here and nowhere else — a missing one makes a
                // single-day bar an empty range, i.e. invisible; a doubled one
                // claims a day the traveller was elsewhere.
                // addDay() and not addRealDays(): "the next day" is CALENDAR
                // arithmetic, not elapsed time. Measured on the 25-hour Berlin
                // fall-back day, 2026-10-25 -> addDay() 2026-10-26 against
                // addRealDays(1) 2026-10-25 — the elapsed variant loses a day.
                // 'UTC' is named so the result provably consults no zone at all.
                // Measured across UTC, Europe/Berlin, America/New_York,
                // America/Santiago and America/Havana x 8 boundary days (month,
                // leap day, New Year, all three midnight/DST shifts): 40 of 40
                // correct. And, against the obvious suspicion: the UNNAMED
                // variant agreed in all 40 too — even where local midnight does
                // not exist (Santiago 2026-09-06, Havana 2026-03-08), addDay()
                // moves the date component and comes out right. So this is not
                // repair of a measured defect, it is one dependency fewer.
                'end' => CarbonImmutable::parse($run['to'], 'UTC')->addDay()->format('Y-m-d'),
            ];
        }
    }

    // A LIST, so the JSON is a JS array: nostrCal.js maps over this payload, and
    // .map() on an object throws — which would not misplace a bar, it would stop
    // the calendar from initialising at all.
    $this->eventBars = $bars;
});

$deleteDays = function ($days) {
    $currentYear = $this->currentYear ?? now()->year;

    /*
     | THE DAY LIST IS THE INSTRUCTION — and it used to be second-guessed. This
     | query carried the displayed year as two extra bounds on top of the
     | whereIn, and the effect was silent data left behind rather than data
     | protected: a stay crossing New Year is ONE stay here (App\Support\
     | ContiguousStays; its bar spans the boundary in the grid), so a cross-year
     | day list is ordinary input — from "Clear these days" over a selection that
     | began in December, and from pulling such a bar's edge back. The half
     | outside the year on screen was dropped without a word. Measured on bda7949
     | against the real component, 2026-08-12: deleteDays(['2027-01-01',
     | '2027-01-02']) with currentYear 2026 deleted NOTHING, all four days stayed.
     | Pre-existing, not a P5 regression, and it hit the modal path already.
     |
     | The bound was also the one asymmetry between the two write directions:
     | saveDays() below has never had it and writes across the year (measured the
     | same day), so the same list could be written and then not taken back.
     |
     | What actually fences this query is the user scope plus the explicit day
     | list, both still here. The year belongs on the RELOAD underneath, which is
     | a statement about the grid and not about the write.
     | Pinned from four sides in tests/Feature/CalendarDeleteAcrossYearsTest.php,
     | including that the wider delete stays inside one user's rows.
     */
    Event::query()
        ->where('user_id', auth()->id())
        ->whereIn('day', $days)
        ->delete();

    $this->events = Event::query()
        ->where('day', '>=', $currentYear . '-01-01')
        ->where('day', '<=', $currentYear . '-12-31')
        ->where('user_id', auth()->id())
        ->get()
        ->map(fn($event) => [
            'country' => country($event->country)->getIsoAlpha2(),
            'title' => country($event->country)->getEmoji() . ' ' . country($event->country)->getName(),
            'start' => $event->day,
        ])
        ->toArray();

    $this->refreshRecentCountries();
    $this->refreshUntrackedDays();
    // After $this->events, because the bars take their country spelling from it.
    $this->refreshEventBars();
};

$saveDays = function ($days, $country) {
    $currentYear = $this->currentYear ?? now()->year;

    // save each day in the database
    foreach ($days as $day) {
        $event = Event::firstOrNew([
            'user_id' => auth()->id(),
            'day' => $day,
        ]);
        $event->user_id = auth()->id();
        $event->day = $day;
        $event->country = str($country)->lower();
        $event->save();
    }

    $this->events = Event::query()
        ->where('day', '>=', $currentYear . '-01-01')
        ->where('day', '<=', $currentYear . '-12-31')
        ->where('user_id', auth()->id())
        ->get()
        ->map(fn($event) => [
            'country' => country($event->country)->getIsoAlpha2(),
            'title' => country($event->country)->getEmoji() . ' ' . country($event->country)->getName(),
            'start' => $event->day,
        ])
        ->toArray();

    $this->refreshRecentCountries();
    $this->refreshUntrackedDays();
    // After $this->events, because the bars take their country spelling from it.
    $this->refreshEventBars();
};

mount(function () {
    $currentYear = $this->currentYear ?? now()->year;

    $this->events = Event::query()
        ->where('day', '>=', $currentYear . '-01-01')
        ->where('day', '<=', $currentYear . '-12-31')
        ->where('user_id', auth()->id())
        ->get()
        ->map(fn($event) => [
            'country' => country($event->country)->getIsoAlpha2(),
            'title' => country($event->country)->getEmoji() . ' ' . country($event->country)->getName(),
            'start' => $event->day,
        ])
        ->toArray();

    $this->start = auth()->user()->pt_start?->format('Y-m-d');

    $this->refreshRecentCountries();

    // After $start, because the lower bound comes from it.
    $this->refreshUntrackedDays();

    // After $this->events, because the bars take their country spelling from it.
    // The grid's event source, so it has to exist before the first paint —
    // Alpine entangles it and reads it in init().
    $this->refreshEventBars();
});

updated([
    'currentYear' => function () {
        $currentYear = $this->currentYear ?? now()->year;

        $this->events = Event::query()
            ->where('day', '>=', $currentYear . '-01-01')
            ->where('day', '<=', $currentYear . '-12-31')
            ->where('user_id', auth()->id())
            ->get()
            ->map(fn($event) => [
                'country' => country($event->country)->getIsoAlpha2(),
                'title' => country($event->country)->getEmoji() . ' ' . country($event->country)->getName(),
                'start' => $event->day,
            ])
            ->toArray();

        // The year on screen changed, so every chip's figure now describes a
        // different year — the chip set has to follow it.
        $this->refreshRecentCountries();
        $this->refreshUntrackedDays();
        // And the grid now shows a different year, so a different set of runs
        // intersects it. After $this->events, for the country spelling.
        $this->refreshEventBars();
    },
    'start' => function () {
        $user = auth()->user();
        $user->pt_start = \Illuminate\Support\Carbon::parse($this->start)->startOfDay();
        $user->save();

        // The lower bound just moved.
        $this->refreshUntrackedDays();
    },
]);

?>

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <h2 class="font-display font-bold text-xl text-navy-900 dark:text-navy-50 leading-tight">
                    {{ __('Calendar') }}
                </h2>
                <span class="eyebrow text-navy-400 dark:text-navy-300 hidden sm:inline">Every day, one country</span>
            </div>
        </div>
    </x-slot>
    @volt('calendar')
    <div x-data="nostrCal(@this)" class="px-4 sm:px-6 lg:px-8 py-6 max-w-[110rem] mx-auto">
        {{-- Start-date field, framed as a travel document line --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5 rounded-xl border border-navy-100 dark:border-white/10 bg-white dark:bg-navy-900 px-4 py-3.5 shadow-sm">
            <div>
                <label class="eyebrow text-gold-600 dark:text-gold-300 block mb-1" for="start">
                    Perpetual-traveler start date
                </label>
                <p class="text-sm text-navy-500 dark:text-navy-300">
                    Days before this are counted apart from your residency days.
                </p>
            </div>
            <input
                    id="start"
                    x-ref="startField"
                    type="date"
                    class="block w-full sm:w-auto rounded-lg border-navy-200 dark:border-white/10 bg-white dark:bg-navy-950/60 text-navy-900 dark:text-navy-100 shadow-sm focus:border-gold-400 focus:ring-2 focus:ring-gold-400/40 text-base sm:text-sm px-3.5 py-2.5 [color-scheme:light] dark:[color-scheme:dark]"
                    wire:model.live.debounce="start"/>
        </div>

        {{-- Mobile/Tablet Tab-Switch — segmented control --}}
        <div class="flex lg:hidden p-1 border border-navy-100 dark:border-white/10 rounded-xl mb-4 bg-white dark:bg-navy-900 shadow-sm gap-1">
            <button type="button" @click="tab='calendar'"
                    :class="tab==='calendar' ? 'bg-gold-400 text-navy-950 shadow-sm' : 'text-navy-600 dark:text-navy-300'"
                    class="flex-1 min-h-[44px] rounded-lg text-sm font-semibold transition-colors">
                Calendar
            </button>
            <button type="button" @click="tab='stats'"
                    :class="tab==='stats' ? 'bg-gold-400 text-navy-950 shadow-sm' : 'text-navy-600 dark:text-navy-300'"
                    class="flex-1 min-h-[44px] rounded-lg text-sm font-semibold transition-colors">
                Statistics
            </button>
        </div>

        <div class="bg-white dark:bg-navy-900 overflow-hidden border border-navy-100 dark:border-white/10 shadow-sm rounded-2xl">
            <div class="p-3 sm:p-6 text-navy-900 dark:text-navy-100">
                <div class="lg:flex lg:space-x-6">
                    {{-- Calendar column: the year's data-quality line, then the grid --}}
                    <div class="w-full lg:w-8/12 xl:w-9/12">
                        @php
                            $ptrYear = (int) ($currentYear ?? now()->year);
                            $ptrGaps = count($untrackedDays ?? []);
                            // Every date below comes normalised out of
                            // refreshUntrackedDays(); nothing is re-derived here.
                            $ptrFrom = data_get($untrackedWindow, 'from');
                            $ptrTo = data_get($untrackedWindow, 'to');
                            $ptrStart = data_get($untrackedWindow, 'start');
                            $ptrDate = fn ($iso) => \Carbon\CarbonImmutable::parse($iso)->format('d.m.Y');
                        @endphp

                        {{-- Untracked-days line. Sits above FullCalendar's own toolbar, so the
                             pane reads summary -> navigation -> grid. This sentence is also the
                             accessible carrier of the hatch marking: the texture in the grid is a
                             visual index into it. (FullCalendar owns the cell DOM, so a per-cell
                             hidden label would mean mount/unmount bookkeeping inside foreign
                             nodes — deliberately left out of this phase, and named as such.)
                             The count is count($untrackedDays), i.e. the same list the grid
                             hatches — never a second calculation. --}}
                        <div x-show="tab === 'calendar'"
                             x-cloak
                             class="lg:!block pb-3 mb-4 border-b border-navy-100 dark:border-white/10">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                @if(! $ptrStart)
                                    <div class="flex-1 min-w-[14rem]">
                                        <p class="eyebrow text-navy-400 dark:text-navy-300">Untracked days</p>
                                        <p class="mt-1 text-sm text-navy-500 dark:text-navy-300">
                                            Set your start date, and every day since then that has no
                                            country gets marked in the grid.
                                        </p>
                                    </div>
                                    <button type="button"
                                            @click="$refs.startField?.focus()"
                                            class="inline-flex items-center justify-center px-3 py-2 min-h-[44px] text-sm font-semibold rounded-lg border border-navy-200 dark:border-white/10 text-navy-900 dark:text-navy-100 hover:border-gold-400 hover:bg-gold-400/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 transition-colors whitespace-nowrap">
                                        Set start date
                                    </button>
                                @elseif(! $ptrFrom)
                                    <div>
                                        <p class="eyebrow text-navy-400 dark:text-navy-300">Untracked &middot; {{ $ptrYear }}</p>
                                        <p class="mt-1 text-sm text-navy-500 dark:text-navy-300">
                                            Nothing to check in {{ $ptrYear }} yet. Days are checked from
                                            your start date ({{ $ptrDate($ptrStart) }}) up to the last
                                            day that is over.
                                        </p>
                                    </div>
                                @elseif($ptrGaps === 0)
                                    <div>
                                        <p class="eyebrow text-navy-400 dark:text-navy-300">Untracked &middot; {{ $ptrYear }}</p>
                                        <p class="mt-1 text-sm text-navy-500 dark:text-navy-300">
                                            Every day from {{ $ptrDate($ptrFrom) }} to {{ $ptrDate($ptrTo) }}
                                            has a country.
                                        </p>
                                    </div>
                                @else
                                    <span class="ptr-untracked-swatch shrink-0" aria-hidden="true"></span>
                                    <div>
                                        <p class="eyebrow text-navy-400 dark:text-navy-300">Untracked &middot; {{ $ptrYear }}</p>
                                        <p class="mt-1 text-sm text-navy-700 dark:text-navy-200">
                                            <span class="font-mono text-base font-bold text-navy-900 dark:text-navy-50">{{ $ptrGaps }}</span>
                                            {{ $ptrGaps === 1 ? 'day has' : 'days have' }} no country —
                                            hatched in the grid,
                                            <span class="whitespace-nowrap">{{ $ptrDate($ptrFrom) }} – {{ $ptrDate($ptrTo) }}</span>.
                                            Days still to come are not gaps.
                                        </p>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- THE DAY CURSOR, SPOKEN — for both of them. Tab reaches the grid
                             and a stay bar; the arrow keys walk days either way
                             (resources/js/nostrCal.js). The cursor mark is visual, so on its
                             own it would make the walk operable but not perceivable — this
                             region says the day out loud instead, and on a day cell it adds
                             what the cell holds, which the cell's own name (FullCalendar's
                             "March 10, 2026") does not say. It is the same division of
                             labour the untracked sentence above uses: a texture in the grid,
                             a sentence for anyone who cannot see it.
                             OUTSIDE wire:ignore on purpose, so Alpine owns its text; and
                             sr-only rather than hidden, because an aria-live region that is
                             display:none is not announced at all. Empty until something in
                             the grid is focused, so it says nothing on load. --}}
                        <p class="sr-only" aria-live="polite" x-text="cursorSpoken"></p>

                        <div wire:ignore
                             x-show="tab === 'calendar'"
                             x-cloak
                             class="lg:!block"
                             x-ref="cal"></div>
                    </div>

                    {{-- Stats pane --}}
                    <div x-show="tab === 'stats'"
                         x-cloak
                         class="w-full lg:w-4/12 xl:w-3/12 lg:!block mt-6 lg:mt-0 lg:border-l lg:border-navy-100 lg:dark:border-white/10 lg:pl-6">
                        <div class="w-full">
                            <div class="hidden lg:flex items-center gap-2 mb-4">
                                <h3 class="font-display font-semibold text-navy-900 dark:text-navy-50">Your stays</h3>
                                <span class="eyebrow text-navy-400 dark:text-navy-300">by country</span>
                            </div>
                            <dl class="space-y-3 w-full">
                                @php
                                    /*
                                     | The run detection that used to sit here ran on the
                                     | year-bound $events and therefore halved every stay across
                                     | New Year. It now lives in App\Support\ContiguousStays,
                                     | fed with the whole history by the $contiguousStays computed
                                     | property above — see the comment there for why the runs are
                                     | history-wide while everything below stays year-bound.
                                     | $ptrStays is keyed by the same title as the cards, because
                                     | both titles come from the one stored country code.
                                     |
                                     | The date sort stays: it is what puts the country cards in
                                     | the order of their first stamped day (groupBy keeps first
                                     | appearance), which is a separate job from run detection.
                                     */
                                    usort($events, fn ($a, $b) => strcmp($a['start'], $b['start']));
                                    $ptrStays = $this->contiguousStays;
                                    // Same clamp as the computed property, because the seam line
                                    // prints this year NEXT TO the day share computed for it —
                                    // printing an unclamped year there would label the figure with
                                    // a year it was not counted for.
                                    $ptrStayYear = max(1970, min(9999, (int) ($currentYear ?? now()->year)));
                                    $events = collect($events)
                                        ->groupBy('title')
                                        ->map(function($event) use($start) {
                                            $totalDaysWithoutPt = 0;
                                            $totalDaysAsPt = 0;
                                            if (!$start) {
                                                $totalDaysWithoutPt = count($event);
                                            }
                                            if ($start) {
                                                $totalDaysWithoutPt = $event
                                                    ->filter(fn($e) => $e['start'] < $start)
                                                    ->count();
                                                $totalDaysAsPt = $event
                                                    ->filter(fn($e) => $e['start'] >= $start)
                                                    ->count();
                                            }
                                            return [
                                                'total_days' => count($event),
                                                'total_days_without_pt' => $totalDaysWithoutPt,
                                                'total_days_as_pt' => $totalDaysAsPt,
                                            ];
                                    });
                                @endphp
                                @if(count($events) === 0)
                                    <div class="pt-stamp bg-navy-50/60 dark:bg-navy-950/40 px-5 py-8 text-center">
                                        <div class="text-3xl mb-3">🛂</div>
                                        <p class="font-display font-semibold text-navy-900 dark:text-navy-50">No stamps yet</p>
                                        <p class="mt-1.5 text-sm text-navy-500 dark:text-navy-300">
                                            Drag across days in the calendar and pick a country. Each stay
                                            shows up here as a stamp with its day-count.
                                        </p>
                                    </div>
                                @endif
                                @foreach($events as $c => $event)
                                    <div class="pt-stamp bg-white dark:bg-navy-900 p-4">
                                        {{-- Stamp head: country + hero day-count --}}
                                        <div class="flex items-start justify-between gap-3">
                                            <dd class="font-display font-semibold text-base tracking-tight text-navy-900 dark:text-navy-50 leading-snug">{{ $c }}</dd>
                                            <div class="text-right shrink-0">
                                                <div class="font-mono text-2xl font-bold leading-none text-navy-900 dark:text-navy-50">{{ $event['total_days'] }}</div>
                                                <div class="eyebrow text-navy-400 dark:text-navy-300 mt-1">days total</div>
                                            </div>
                                        </div>

                                        {{-- PT split --}}
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 mt-3">
                                            <dt class="font-mono text-xs text-gold-600 dark:text-gold-300">
                                                {{ $event['total_days_as_pt'] }} as PT
                                            </dt>
                                            @if($event['total_days_without_pt'] > 0)
                                                <dt class="font-mono text-xs text-navy-500 dark:text-navy-300">
                                                    {{ $event['total_days_without_pt'] }} before PT
                                                </dt>
                                            @endif
                                        </div>

                                        {{-- Contiguous stays timeline --}}
                                        <dt class="eyebrow text-navy-400 dark:text-navy-300 mt-4 pt-3 border-t border-navy-100 dark:border-white/10">
                                            Contiguous stays
                                        </dt>
                                        <ol role="list" class="mt-2">
                                            @foreach($ptrStays[$c] ?? [] as $key => $stay)
                                                <li>
                                                    <div class="flex items-baseline justify-between gap-2">
                                                        <span class="font-mono text-sm font-bold text-navy-900 dark:text-navy-50">{{ $stay['days'] }}d</span>
                                                        <time class="font-mono text-xs text-navy-500 dark:text-navy-300">
                                                            {{ \Illuminate\Support\Carbon::parse($stay['from'])->format('d.m.Y') }}
                                                            &rarr; {{ \Illuminate\Support\Carbon::parse($stay['to'])->format('d.m.Y') }}
                                                        </time>
                                                    </div>
                                                    {{-- THE YEAR SEAM. A run is measured over the whole history, the
                                                         card head counts the displayed year — so a 9-day run can sit
                                                         under a head that says 4, and without this line one of the
                                                         two figures reads as wrong. It states the arithmetic that
                                                         joins them, in the year's own terms, and it is the ONLY
                                                         carrier needed: the dashed rule is the tear in the page, the
                                                         sentence is what a screen reader gets (WCAG 1.4.1 — no
                                                         meaning on colour alone). Rendered only for runs that
                                                         actually cross a New Year, so it never becomes furniture. --}}
                                                    @if($stay['spans_years'])
                                                        <p class="ptr-seam">
                                                            <span class="ptr-seam-n">{{ $stay['days_in_year'] }}</span> of
                                                            these <span class="ptr-seam-n">{{ $stay['days'] }}</span>
                                                            days {{ $stay['days_in_year'] === 1 ? 'falls' : 'fall' }} in {{ $ptrStayYear }}
                                                        </p>
                                                    @endif
                                                </li>
                                                @if(!$loop->last)
                                                    <li class="pt-gap-rail py-1.5 my-1">
                                                        @php
                                                            // Carbon 3 gibt diffInDays() vorzeichenbehaftet zurueck (Carbon 2:
                                                            // absolut). Die Richtung muss deshalb stimmen: vom letzten Tag
                                                            // dieses Aufenthalts zum ersten des naechsten. Kein abs() --
                                                            // eine negative Zahl waere ein echter Sortierfehler und soll
                                                            // sichtbar bleiben, statt still plausibel zu werden.
                                                            $daysInBetween = \Illuminate\Support\Carbon::parse($stay['to'])->diffInDays(\Illuminate\Support\Carbon::parse($ptrStays[$c][$key+1]['from'])) - 1;
                                                        @endphp
                                                        <span class="font-mono text-xs font-medium @if($daysInBetween < 21) text-risk dark:text-risk-bright @else text-ok dark:text-ok-bright @endif">
                                                            {{ $daysInBetween }} days gap{{ $daysInBetween < 21 ? ' · tight' : '' }}
                                                        </span>
                                                    </li>
                                                @endif
                                            @endforeach
                                        </ol>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Country-Select Modal --}}
        <div @keydown.escape.window="modalOpen = false"
             class="relative z-50 w-auto h-auto" x-cloak>
            <template x-teleport="body">
                <div x-show="modalOpen"
                     class="fixed inset-0 z-[99] flex items-end sm:items-center justify-center" x-cloak>
                    <div x-show="modalOpen"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0"
                         x-transition:enter-end="opacity-100"
                         x-transition:leave="ease-in duration-300"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         @click="modalOpen=false"
                         class="absolute inset-0 w-full h-full bg-navy-950/70 backdrop-blur-sm"></div>
                    {{-- .noreturn, because x-trap's own return focus is measurably wrong
                         here and cannot be made right from the template: the trap
                         activates on a 15ms timeout, by which time the country search
                         below has already auto-focused itself on $nextTick — so the node
                         focus-trap records as "focused before activation" is that search
                         field, and on release it focuses a field that is display:none by
                         then. The browser drops focus to BODY (measured on 6d7f51b and on
                         8dd30f4 alike). nostrCal.js's $watch on modalOpen states the way
                         back instead, keyed by the DAY the modal is about — see the block
                         there for why an element reference cannot do that job.

                         .noautofocus closes a SECOND race, found in P15
                         (tests/Browser/CalendarKeyboardTest.php:231 flaky 10-15%). The
                         15ms setTimeout that schedules `trap.activate()` on open is never
                         cancelled if the modal closes again before it fires (vendor/
                         livewire/livewire/dist/livewire.esm.js:5760 — deactivate() bails
                         out early via `if (!state.active) return`, because activate()'s
                         BODY has not run yet, only been scheduled). The orphaned callback
                         still fires ~15ms after open regardless, and it unconditionally
                         calls _tryFocus(getInitialFocusNode()) — which, while the leave
                         transition is still mid-flight (container is display:block for
                         ~200ms after modalOpen goes false), finds real tabbable nodes and
                         moves focus onto one of them (measured: the header's close
                         button), stealing it from the day/bar the $watch below had
                         already restored it to. When the transition later finishes and
                         Alpine sets display:none on the container, the browser drops the
                         focus it is holding on a now-hidden element to BODY — the exact
                         signature P15 measured. Reproduced deterministically by
                         intercepting `setTimeout(fn, 15)` and firing the captured
                         callback by hand after an Escape close: activeElement moved
                         INPUT -> BODY -> (our restore) bar -> BUTTON (the stale call) ->
                         BODY (on transition end), never landing back on the bar.
                         `.noautofocus` sets `options.initialFocus = false`
                         (livewire.esm.js:5726), which makes getInitialFocusNode() return
                         `false` unconditionally, so _tryFocus is a no-op whenever
                         activate() runs — on time or stale. It does not touch `.inert`
                         (onPostActivate, unrelated) or Tab-cycling (built from
                         state.tabbableGroups, unrelated to initialFocus); the modal still
                         needs SOMETHING to move focus in on open, and this file already
                         supplies that independently (the x-effect below, `$refs.q?.focus()`
                         on $nextTick) — so this removes a competing, racy focus write
                         rather than the only one. --}}
                    <div x-show="modalOpen"
                         x-trap.inert.noscroll.noreturn.noautofocus="modalOpen"
                         x-transition:enter="ease-out duration-300"
                         x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave="ease-in duration-200"
                         x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                         x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                         class="relative w-full sm:w-[95vw] max-w-screen-2xl max-h-[90vh] sm:max-h-[85vh] overflow-y-auto py-4 px-4 sm:py-6 sm:px-7 bg-white dark:bg-navy-900 border border-navy-100 dark:border-white/10 rounded-t-2xl sm:rounded-2xl shadow-2xl">
                        @php
                            /*
                             | SERVER-SIDE HALF OF THE PREVIEW. Both figures here are static
                             | per render — they describe the YEAR, not the selected range, so
                             | opening the modal needs no roundtrip and the head never reflows
                             | a moment after the tap.
                             |
                             | $ptrYearDays is the same number the stats pane prints as "days
                             | total": same array ($this->events — note the stats block above
                             | overwrites its LOCAL $events with the grouping, $this->events is
                             | untouched), same groupBy, same count, only keyed by ISO code
                             | instead of by title. Title and code are both derived from the one
                             | stored country, so the keying is a relabel, not a second
                             | computation. That is what makes the "before" figure in the modal
                             | and the figure in the stats tab ONE number rather than two that
                             | happen to agree. All four queries bound to the calendar year, so
                             | a count over this array IS the year total — no year filter needed
                             | here.
                             |
                             | $ptrRangeCountries is every country that can possibly show up in
                             | the docket: a range day inside the displayed year carries a
                             | country which is, by definition, present in $this->events. So the
                             | rows can be rendered SERVER-side — like the chips and the A–Z
                             | list — and Alpine only decides which of them apply to this range.
                             | Deliberately no x-for/x-if: this modal is teleported to <body>
                             | and morphed by Livewire, and x-show/x-text/x-cloak are the
                             | constructs this file already proves survive that.
                             */
                            $ptrModalYear = (int) ($currentYear ?? now()->year);
                            $ptrYearDays = collect($this->events)->groupBy('country')->map->count();
                            $ptrRangeCountries = $ptrYearDays->keys()
                                ->map(fn ($code) => ['code' => $code, 'name' => country($code)->getName()])
                                ->sortBy('name')
                                ->values();
                        @endphp
                        <div class="pb-3 sticky top-0 bg-white dark:bg-navy-900 z-10">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="eyebrow text-gold-600 dark:text-gold-300 mb-1">Stamp these days</p>
                                    <h3 class="font-display text-lg font-semibold text-navy-900 dark:text-navy-50">Choose country</h3>
                                    <p class="font-mono text-xs text-navy-500 dark:text-navy-300 mt-0.5" x-text="rangeLabel()"></p>
                                </div>
                                <button @click="modalOpen=false"
                                        aria-label="Close"
                                        class="flex items-center justify-center w-11 h-11 shrink-0 text-navy-500 dark:text-navy-300 rounded-full hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-400">
                                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                         stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>

                            {{-- THE DOCKET — what these days hold right now, before anything is
                                 written. Country-INDEPENDENT on purpose: the composition of the
                                 range is a fact, the same for every choice, and it is the only
                                 honest place to say that days already belonging to another
                                 country will move. A per-country "after" figure here would have
                                 to assume a target that has not been picked yet, and it would
                                 contradict the chips below — so exactly one arrow exists in this
                                 modal, and it sits on the chips, where the target is known.
                                 Inside the sticky head, so the consequence stays on screen while
                                 the country list scrolls under it. --}}
                            <div class="ptr-docket" x-show="ptrPreview" x-cloak>
                                <p class="eyebrow text-gold-600 dark:text-gold-300">On these days now</p>

                                <div class="mt-2 flex flex-wrap items-baseline gap-x-5 gap-y-1.5">
                                    {{-- Days with no country. Same hatch token as the grid cells,
                                         so the mark here and the marks there are one source. --}}
                                    <span class="ptr-line" x-show="ptrFree > 0" x-cloak>
                                        <span class="ptr-hatch-chip" aria-hidden="true"></span>
                                        <span class="ptr-line-n" x-text="ptrFree"></span>
                                        <span class="ptr-line-t"><span
                                                    x-text="ptrFree === 1 ? 'day has' : 'days have'"></span> no country</span>
                                    </span>

                                    {{-- Days that belong to a country today. These are the days
                                         that MOVE, and the year total in brackets is how much is
                                         at stake for that country. No "after" figure and no risk
                                         colour: a day moving is a normal operation, and the
                                         after-figure depends on a choice not yet made. --}}
                                    @foreach($ptrRangeCountries as $ptrCountry)
                                        <span class="ptr-line"
                                              wire:key="dock_{{ $ptrCountry['code'] }}"
                                              x-show="ptrHeldDays('{{ $ptrCountry['code'] }}') > 0" x-cloak>
                                            <span class="text-base leading-none shrink-0"
                                                  aria-hidden="true">{{ country($ptrCountry['code'])->getEmoji() }}</span>
                                            <span class="ptr-line-n"
                                                  x-text="ptrHeldDays('{{ $ptrCountry['code'] }}')"></span>
                                            <span class="ptr-line-t"><span
                                                        x-text="ptrHeldDays('{{ $ptrCountry['code'] }}') === 1 ? 'day is' : 'days are'"></span>
                                                {{ $ptrCountry['name'] }}
                                                <span class="text-navy-500 dark:text-navy-300">({{ $ptrYearDays->get($ptrCountry['code'], 0) }} in {{ $ptrModalYear }})</span>
                                            </span>
                                        </span>
                                    @endforeach

                                    {{-- Days the displayed year cannot speak for. Only reachable
                                         from the mobile month grid, which renders bleed cells from
                                         the neighbouring month; the year view does not. Their
                                         country is genuinely unknown here, so they are neither
                                         "no country" nor part of any total. --}}
                                    <span class="ptr-line" x-show="ptrOutside > 0" x-cloak>
                                        <span class="ptr-line-n" x-text="ptrOutside"></span>
                                        <span class="ptr-line-t"><span
                                                    x-text="ptrOutside === 1 ? 'day falls' : 'days fall'"></span>
                                            outside {{ $ptrModalYear }} &mdash; not in the counts below</span>
                                    </span>
                                </div>

                                {{-- The overwrite rule, stated once — and only when something is
                                     actually at stake, which keeps the head short on the common
                                     path (stamping days that have no country yet). --}}
                                <p class="mt-2 text-sm text-navy-600 dark:text-navy-300"
                                   x-show="ptrHeldTotal > 0" x-cloak>
                                    Days that already have a country move to the one you pick.
                                </p>
                            </div>
                        </div>
                        @php
                            $allCountries = $this->countries->values();
                            $grouped = $allCountries
                                ->groupBy(fn($c) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($c['name'], 0, 1)))
                                ->sortKeys();
                            /*
                             | letter => [{n: lowercased name, c: code}] for Alpine's instant
                             | filter, its counts AND its Enter key. The CODE travels with the
                             | name because "the first match" has to be answerable from this
                             | list alone — see selectFirst() below for what reading it off the
                             | rendered list cost.
                             |
                             | Built from $grouped, the same collection the sections below are
                             | rendered from, so the order here IS the order on screen: letters
                             | sorted, and within a letter the order the buttons are printed in.
                             */
                            $groupsForJs = $grouped
                                ->map(fn($list) => $list->map(fn($c) => [
                                    'n' => \Illuminate\Support\Str::lower($c['name']),
                                    'c' => $c['iso_3166_1_alpha2'],
                                ])->values()->all())
                                ->all();
                            $letters = $grouped->keys()->all();
                        @endphp
                        {{-- THE TOP MATCH COMES FROM THE LIST BELOW — never from the rendered page.

                             selectFirst() used to take the first button whose offsetParent was
                             not null, i.e. it asked the DOM which countries are on screen. The
                             DOM is the wrong authority for that, and not by a little: x-show
                             hands its hide to `_x_toggleAndCascadeWithTransitions`, and that
                             function defers the actual `display:none` to `requestAnimationFrame`
                             (vendor/livewire/livewire/dist/livewire.esm.js:2465, applied at
                             :2487). The filter is therefore up to a FRAME behind the field — by
                             design, and nothing here can change that.

                             Enter arriving inside that frame read a list that had not narrowed
                             yet and stamped whatever stood first in the document. Measured on
                             the unfixed stand, 26 runs of CalendarKeyboardTest.php: 6 red
                             (23 %). A capture-phase probe caught the state at the instant the
                             handler ran: q was Germany and total was 1 in every red run, while
                             the number of still-visible buttons was 4, 7 and once all 250. The
                             day was stamped dz (Algeria) twice and af (Afghanistan) once — not
                             one wrong country, but whichever one had not been hidden yet. No
                             Livewire morph was involved (morph count 0 in every run); this is
                             Alpine's own frame, not a re-render.

                             So the answer is derived from `groups`, in render order, through the
                             same matchName() the buttons' x-show uses: same predicate, same
                             data, same order — the first visible button by construction, and
                             true the moment q changes rather than a frame later. The mouse path
                             never had the defect, because a click carries the code of the button
                             that was clicked. Pinned by tests/Browser/CountrySearchTest.php.

                             (The prose lives out here on purpose: an x-data attribute is
                             delimited by double quotes, so a comment inside it that quotes
                             anything ends the attribute and silently empties the component.) --}}
                        <div class="relative w-auto"
                             x-data="{
                                q: '',
                                letters: @js($letters),
                                groups: @js($groupsForJs),
                                get needle() { return this.q.trim().toLowerCase(); },
                                matchName(n) { return !this.needle || n.includes(this.needle); },
                                groupCount(l) {
                                    const g = this.groups[l];
                                    if (!g) return 0;
                                    return this.needle ? g.filter(e => this.matchName(e.n)).length : g.length;
                                },
                                get total() {
                                    return this.letters.reduce((s, l) => s + this.groupCount(l), 0);
                                },
                                clear() { this.q = ''; this.$refs.q?.focus(); },
                                jump(l) { document.getElementById('grp-' + l)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); },
                                // The first match in render order, from the data — see the
                                // block above the element for why never from the page.
                                firstMatch() {
                                    for (const l of this.letters) {
                                        const hit = (this.groups[l] || []).find(e => this.matchName(e.n));
                                        if (hit) return hit;
                                    }
                                    return null;
                                },
                                selectFirst() {
                                    const hit = this.firstMatch();
                                    if (hit) this.setCountry(hit.c);
                                },
                             }"
                             x-effect="modalOpen ? $nextTick(() => $refs.q?.focus()) : (q = '')">

                            {{-- Controls: instant search + clear-days --}}
                            <div class="py-4 flex flex-col sm:flex-row sm:items-center gap-3">
                                <div class="flex items-center px-3 border border-navy-200 dark:border-white/10 bg-white dark:bg-navy-950/60 rounded-lg flex-1 focus-within:border-gold-400 focus-within:ring-2 focus-within:ring-gold-400/40 transition">
                                    <svg class="w-4 h-4 text-navy-400 dark:text-navy-300 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                        <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3" stroke-linecap="round"/>
                                    </svg>
                                    <input
                                            x-ref="q"
                                            x-model="q"
                                            @keydown.enter.prevent="selectFirst()"
                                            @keydown.escape="if (q) { q = ''; $event.stopPropagation(); }"
                                            type="text"
                                            aria-label="Search countries"
                                            class="flex w-full px-2 py-3 text-base sm:text-sm bg-transparent border-0 rounded-lg outline-none focus:outline-none focus:ring-0 focus:border-0 placeholder:text-navy-400 dark:placeholder:text-navy-300 text-navy-900 dark:text-navy-100 h-11 disabled:cursor-not-allowed disabled:opacity-50"
                                            placeholder="Search countries…" autocomplete="off" autocorrect="off"
                                            spellcheck="false">
                                    <button type="button" x-show="q" x-cloak @click="clear()" aria-label="Clear search"
                                            class="flex items-center justify-center w-9 h-9 shrink-0 text-navy-400 dark:text-navy-300 rounded-full hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-400">
                                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" d="M6 18 18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                                <button type="button" @click="deleteDays"
                                        class="inline-flex items-center justify-center gap-2 min-h-[44px] rounded-lg bg-risk px-4 py-3 sm:py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-risk/90 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-risk whitespace-nowrap transition">
                                    Clear these days
                                </button>
                            </div>

                            {{-- Recently used (hidden while searching to keep the field of view clean) --}}
                            @if(count($this->selectedCountries))
                                <div x-show="!needle" x-cloak>
                                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 mt-1">
                                        <div class="eyebrow text-navy-400 dark:text-navy-300">
                                            Recently used
                                        </div>
                                        {{-- What the arrow on each chip means, and in which unit.
                                             One label for the whole row instead of a unit repeated
                                             on every chip. --}}
                                        <div class="eyebrow text-navy-400 dark:text-navy-300">
                                            &rarr; {{ $ptrModalYear }} total
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap gap-1.5 py-3 border-b border-navy-100 dark:border-white/10">
                                        {{-- THE ONE ARROW IN THIS MODAL. A chip is a country whose
                                             year total is already a known quantity, so "before →
                                             after" says something here. The A–Z list below gets no
                                             figures on purpose: for the ~245 countries with no days
                                             in this year the pair would be a constant 0 → n on
                                             every row, i.e. decoration that fights the one job that
                                             list has (find a country) — and a badge that is right
                                             *almost* always is the failure mode this feature exists
                                             to avoid. The docket above already states n.
                                             `before` is printed straight from the server; Alpine
                                             only supplies the predicted figure. --}}
                                        @foreach($this->selectedCountries as $country)
                                            @php($ptrChipBefore = $ptrYearDays->get($country, 0))
                                            <button type="button"
                                                    @click="setCountry('{{ $country }}')"
                                                    :aria-label="ptrChipLabel('{{ $country }}', @js(country($country)->getName()), {{ $ptrChipBefore }})"
                                                    wire:key="c_{{ $country }}"
                                                    class="inline-flex items-center gap-2.5 px-3 py-2 text-sm cursor-pointer text-navy-900 dark:text-navy-100 bg-navy-100/70 dark:bg-white/5 border border-transparent hover:border-gold-400 hover:bg-gold-400/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 transition-colors rounded-lg min-h-[44px]">
                                                {{ country($country)->getEmoji() . ' ' . country($country)->getName() }}
                                                <span class="ptr-count" aria-hidden="true">
                                                    <span class="ptr-count-was">{{ $ptrChipBefore }}</span>
                                                    <span class="ptr-count-arrow">&rarr;</span>
                                                    <span class="ptr-count-now"
                                                          x-text="ptrAfter('{{ $country }}', {{ $ptrChipBefore }})"></span>
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- List heading + live count --}}
                            <div class="flex items-baseline justify-between gap-3 mt-4 mb-1">
                                <div class="eyebrow text-navy-400 dark:text-navy-300">
                                    <span x-show="!needle">All countries</span>
                                    <span x-show="needle" x-cloak x-text="total + (total === 1 ? ' match' : ' matches')"></span>
                                </div>
                                <span x-show="needle && total > 0" x-cloak class="eyebrow text-navy-400 dark:text-navy-300">&crarr; stamps top match</span>
                            </div>

                            {{-- Grouped list + A–Z jump rail --}}
                            <div class="relative">
                                <div x-ref="list"
                                     class="max-h-[52vh] sm:max-h-[56vh] overflow-y-auto pr-1 sm:pr-8 scroll-pt-9"
                                     style="scroll-behavior: smooth;">
                                    @foreach($grouped as $letter => $list)
                                        <section wire:key="grp_{{ $letter }}" x-show="groupCount('{{ $letter }}') > 0" x-cloak>
                                            <div id="grp-{{ $letter }}" class="sticky top-0 z-10 bg-white dark:bg-navy-900 flex items-center gap-3 py-1.5">
                                                <span class="font-mono text-sm font-bold text-gold-600 dark:text-gold-300">{{ $letter }}</span>
                                                <span class="h-px flex-1 bg-navy-100 dark:bg-white/10"></span>
                                            </div>
                                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-1 pb-3 pt-1">
                                                @foreach($list as $country)
                                                    <button type="button"
                                                            data-country
                                                            x-show="matchName(@js(\Illuminate\Support\Str::lower($country['name'])))"
                                                            @click="setCountry('{{ $country['iso_3166_1_alpha2'] }}')"
                                                            wire:key="c_{{ $country['iso_3166_1_alpha2'] }}"
                                                            class="flex items-center gap-2 px-3 py-2 min-h-[44px] text-left text-sm rounded-lg border border-transparent text-navy-700 dark:text-navy-200 hover:border-gold-400 hover:bg-gold-400/10 hover:text-navy-900 dark:hover:text-navy-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 transition-colors">
                                                        <span class="text-base leading-none shrink-0">{{ $country['emoji'] }}</span>
                                                        <span class="truncate">{{ $country['name'] }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        </section>
                                    @endforeach

                                    {{-- No results --}}
                                    <div x-show="total === 0" x-cloak class="pt-stamp bg-navy-50/60 dark:bg-navy-950/40 px-5 py-10 text-center my-2">
                                        <div class="text-2xl mb-2">🔍</div>
                                        <p class="font-display font-semibold text-navy-900 dark:text-navy-50">No country matches “<span x-text="q"></span>”</p>
                                        <p class="mt-1.5 text-sm text-navy-500 dark:text-navy-300">Try the English name — e.g. “Czechia”, “Netherlands”, “United States”.</p>
                                    </div>
                                </div>

                                {{-- A–Z rail: supplementary pointer shortcut (search + list are the
                                     accessible path, so this is hidden on small screens & out of tab order) --}}
                                <nav x-show="!needle" x-cloak aria-label="Jump to letter"
                                     class="hidden sm:flex absolute right-0 top-0 bottom-0 flex-col items-center justify-center select-none">
                                    @foreach($letters as $letter)
                                        <button type="button" tabindex="-1" @click="jump('{{ $letter }}')"
                                                class="font-mono text-[0.65rem] leading-none px-1.5 py-[0.12rem] text-navy-400 dark:text-navy-300 hover:text-gold-600 dark:hover:text-gold-300 rounded transition-colors">
                                            {{ $letter }}
                                        </button>
                                    @endforeach
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

    </div>
    @endvolt
</x-app-layout>
