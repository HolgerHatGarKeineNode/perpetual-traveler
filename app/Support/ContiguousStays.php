<?php

namespace App\Support;

use DateTimeInterface;
use InvalidArgumentException;

/*
 | CONTIGUOUS STAYS — the run detection, lifted out of the @php block in
 | resources/views/pages/calendar.blade.php (:393-419 before P7).
 |
 | WHY IT LEFT THE VIEW: a run is a fact about the user's whole history, while
 | the view is handed one calendar year. Inside the view the only array in reach
 | was the year-bound $events, so a stay across New Year was cut in half and its
 | true length appeared nowhere. Here the input is explicit and the year is a
 | separate argument, so "form over everything / show what touches the year" is
 | two statements instead of one accident.
 |
 | WHY IT IS TIMEZONE-FREE. "Contiguous" is a statement about the CALENDAR;
 | strtotime($a) - strtotime($b) > 86400 is a statement about ELAPSED TIME, and
 | the two part company at every DST backward jump, where a calendar day lasts
 | 25 hours (measured on 11bc357: Europe/Berlin, 2026-10-25 -> 2026-10-26 =
 | 90000 s, and the run tore apart). Days are therefore turned into INTEGER day
 | numbers by calendar arithmetic on the (y, m, d) triple — days-from-civil, the
 | proleptic Gregorian conversion. No timezone database is consulted at any
 | point, so there is no zone left for the result to depend on. Not merely
 | "parsed as UTC": measured under UTC, Europe/Berlin, America/New_York,
 | America/Santiago and America/Havana, identical runs in all five.
 | Second consequence: $year needs no clamping in here, because integer
 | arithmetic has no year PHP's DateTime would refuse. The caller clamps anyway
 | — not against a crash, but so that the page labels the day share with the
 | same year it counted (see the computed property in calendar.blade.php), which
 | is why -5, 0, 99999999 and 500000 from the pre-existing "an absurd
 | client-supplied year" test reach this file as 1970 and 9999.
 | And measured, hence the arithmetic rather than a date object: at 10 958 days
 | of history, one DateTimeImmutable per row cost 44,9 ms for query+map+derive
 | against ~20 ms for the arithmetic (same fixture, separate probe runs, machine
 | noise a few ms either way).
 */
final class ContiguousStays
{
    /**
     * Runs of consecutive days per country, restricted to those that intersect
     * the given calendar year — each with its FULL length and FULL range.
     *
     * @param  iterable<array{title: string, day: DateTimeInterface|string}>  $days
     *                                                                               one entry per tracked day; order irrelevant, sorted here
     * @return array<string, list<array{
     *     days: int, from: string, to: string,
     *     days_in_year: int, spans_years: bool
     * }>> keyed by title, in the order the runs occur, dates as 'Y-m-d'
     */
    public static function intersectingYear(iterable $days, int $year): array
    {
        // [ordinal, title] tuples, not keyed arrays: this list holds one entry per
        // tracked DAY of the whole history (~11 000 for 30 years), so both its
        // memory and the comparator below are on the hot path.
        $rows = [];

        foreach ($days as $row) {
            $rows[] = [self::ordinal($row['day']), (string) $row['title']];
        }

        // Chronological, then by title so that two countries claiming the same
        // day (only reachable while (user_id, day) carries no unique index)
        // resolve deterministically instead of by query order.
        // Compared field by field on purpose: the shorter
        // [$a[0], $a[1]] <=> [$b[0], $b[1]] allocates two arrays per comparison,
        // and at 10 958 shuffled rows that is the single most expensive item in
        // the whole derivation — measured 15,6 ms for the sort with the array
        // literal against 7,1 ms field by field.
        usort($rows, fn ($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));

        /** @var list<array{title: string, from: int, to: int}> $runs */
        $runs = [];
        $open = null;

        foreach ($rows as [$ordinal, $title]) {
            if ($open !== null && $title === $open['title'] && $ordinal <= $open['to'] + 1) {
                // A day already inside the open run (a duplicate row for the same
                // country and day) neither extends nor splits it: one calendar day
                // is one day. The old code counted it twice.
                $open['to'] = max($open['to'], $ordinal);

                continue;
            }

            if ($open !== null) {
                $runs[] = $open;
            }

            $open = ['title' => $title, 'from' => $ordinal, 'to' => $ordinal];
        }

        if ($open !== null) {
            $runs[] = $open;
        }

        $yearFrom = self::fromCivil($year, 1, 1);
        $yearTo = self::fromCivil($year, 12, 31);

        $out = [];

        foreach ($runs as $run) {
            // Intersects the year, which is not "starts or ends in it": a run may
            // enclose the year completely.
            if ($run['to'] < $yearFrom || $run['from'] > $yearTo) {
                continue;
            }

            $from = self::day($run['from']);
            $to = self::day($run['to']);

            $out[$run['title']][] = [
                // Contiguous by construction, so the span IS the day count — no
                // accumulator that could disagree with the two dates it is
                // printed next to.
                'days' => $run['to'] - $run['from'] + 1,
                'from' => $from,
                'to' => $to,
                // The share of this run that the displayed year counts. This is
                // what reconciles a 9-day run with a card head saying 4.
                'days_in_year' => min($run['to'], $yearTo) - max($run['from'], $yearFrom) + 1,
                // Crosses a New Year, i.e. days_in_year < days. No first_year /
                // last_year alongside it: the two dates above already carry them,
                // and the view needs the question answered, not the operands.
                'spans_years' => substr($from, 0, 4) !== substr($to, 0, 4),
            ];
        }

        return $out;
    }

    /**
     * The day number of a stored day. A date-only string is read as it stands —
     * no zone conversion, so '2026-01-05' is the 5th of January whatever the
     * server is set to; a DateTime contributes the date it reports.
     * An unusable value throws instead of being dropped: a silently missing day
     * would shorten a run, i.e. produce a wrong number rather than no number.
     */
    private static function ordinal(DateTimeInterface|string $day): int
    {
        if ($day instanceof DateTimeInterface) {
            return self::fromCivil((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
        }

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]|$)/', $day, $m)) {
            throw new InvalidArgumentException("Not a calendar day: '{$day}'.");
        }

        return self::fromCivil((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /*
     | days-from-civil / civil-from-days, the standard proleptic-Gregorian pair
     | (Howard Hinnant, "chrono-Compatible Low-Level Date Algorithms",
     | https://howardhinnant.github.io/date_algorithms.html — the algorithm C++20
     | <chrono> is specified against). Day 0 is 1970-01-01. Chosen over a date
     | object for two reasons: it consults no timezone database, so the result
     | cannot depend on one, and it is integer-only, which is what makes 10 958
     | rows per render cheap.
     | Verified against PHP's own calendar rather than trusted: every day from
     | 1800-01-01 to 2200-12-31 (146 462 days) agrees with
     | (new DateTimeImmutable($iso, UTC))->getTimestamp() / 86400 in both
     | directions, and day(ordinal(x)) === x for all of them.
     */
    private static function fromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;                                        // [0, 399]
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1; // [0, 365]
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    private static function day(int $ordinal): string
    {
        $z = $ordinal + 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;                                                          // [0, 146096]
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $y = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));                   // [0, 365]
        $mp = intdiv(5 * $doy + 2, 153);                                                    // [0, 11]
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;                                           // [1, 31]
        $m = $mp + ($mp < 10 ? 3 : -9);                                                     // [1, 12]

        return sprintf('%04d-%02d-%02d', $y + ($m <= 2 ? 1 : 0), $m, $d);
    }
}
