#!/usr/bin/env node
/*
 | Runs tests/js/ptrDays.test.js once per timezone.
 |
 | WHY THIS EXISTS AS A SEPARATE RUNNER — and read this carefully, because the
 | obvious reason is no longer the true one. The derivation does NOT read local
 | date getters any more; ptrDays.js walks day ordinals and its stated invariant
 | is that no local getter and no local constructor may appear in it. Its result
 | is therefore zone-INDEPENDENT, measured byte-identical across all 11 zones
 | below (2026-08-11).
 |
 | That is exactly why the matrix stays. It is not a probe for a known zone
 | dependency, it is the guard that keeps the invariant true: the cheapest way to
 | reintroduce both defects is one innocent-looking `new Date(iso)` or
 | `getDate()` in that walk, and the only thing that turns red when someone does
 | is a run in a zone where it matters. Measured on 2026-08-11 against the
 | pre-fix code with the suite as it stood then: three of the nine zones stayed
 | GREEN with BOTH defects present — UTC, Asia/Kolkata and Pacific/Kiritimati,
 | the ones with no DST and no negative offset. A UTC container is one of them.
 | The ambient single run (`yarn test:js`) is a fast local check and nothing more
 | — passing it means very little. (Europe/Berlin was red, 63 pass / 5 fail: the
 | fall-back cases are hard-coded dates, so the month you run in changes
 | nothing.)
 |
 | Zones chosen so that each covers something no other one does: the UTC anchor,
 | both DST hemispheres, the extreme east (+14) and the extreme west (-11/-12),
 | a zone without a whole-hour offset (Kolkata, +05:30), and two zones where
 | LOCAL MIDNIGHT DOES NOT EXIST on the DST day — Havana switches at 00:00,
 | Santiago at 24:00. See the note on those two in the list below before removing
 | one: they cover a failure mode the other nine structurally cannot.
 |
 | Usage:  node tests/js/zones.mjs   (or `yarn test:js:zones`)
 | Exit:   0 only if EVERY zone passes.
 |
 | ANCHORED in composer.json's "test" script, as the last line, so it runs on
 | every `composer test` and cannot rot unnoticed — there is no CI in this repo
 | (no .github/workflows, measured 2026-08-11), so `composer test` is the only
 | thing that gets run anyway. It needs no node_modules; node itself is already
 | mandatory here because of `vite build`.
 |
 | Being last has a measured consequence worth knowing: composer stops a script
 | array at the FIRST failing line, so a red Pest suite hides this matrix
 | entirely. The order is still deliberate — the 49 Pest tests always run and
 | their result stays visible — but after fixing a red Pest run, fix nothing else
 | until `composer test` has been through to the end.
 |
 | It was RED when it was written on 2026-08-11, by design: it pinned two then
 | unfixed defects in the day derivation. Those were fixed the same day, and the
 | gate has been GREEN in every zone since. A red run is a regression — do not
 | dismiss one as known. Pest runs first in the composer script, so its result
 | stays visible either way. To take the gate out again, drop the
 | "node tests/js/zones.mjs" line from composer.json.
 */
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const testFile = path.join(here, 'ptrDays.test.js');

const zones = [
    'UTC',
    'Europe/Berlin',
    'Asia/Kolkata',
    'Pacific/Auckland',
    'Pacific/Kiritimati',
    'America/New_York',
    'America/Los_Angeles',
    'Pacific/Niue',
    'Etc/GMT+12',
    /*
     | The two midnight-transition zones. Measured 2026-08-11 against a candidate
     | implementation that keeps this file's guard but parses the ISO string into
     | a LOCAL Date (`new Date(y, m - 1, d)`) and steps it with setDate() — a
     | plausible fix that all nine zones above accept. Only the walk differs, so
     | what the two zones catch is the midnight class and nothing else:
     |
     |   nine zones above    95 pass, 0 fail   — let it through
     |   America/Havana      93 pass, 2 fail   — caught
     |   America/Santiago    93 pass, 2 fail   — caught
     |
     | Cause: on the DST day local midnight does not exist (Havana 00:00 -> 01:00,
     | Santiago 24:00 -> 01:00), so `new Date(y, m - 1, d)` normalises forward and
     | the walk loses the last day of the range.
     |
     | BOTH zones only bite because a case lands inside their transition window —
     | the zone entry alone covers nothing. Havana is hit by the 2026-03-08 case;
     | Santiago needed one of its own, and the window is narrow: which start dates
     | are affected depends on the RANGE LENGTH, measured over 2026-2028 against
     | the candidate above —
     |
     |   length 1   0 dates          length 3   6: 2026-09-05/06, 2027-09-04/05,
     |   length 2   3 dates                        2028-09-02/03
     |   length 7  18 dates (from 2026-09-01, 2027-08-31, 2028-08-29)
     |
     | The suite's Chile case is a three-day range starting 2026-09-05, i.e. one
     | of six start dates in three years that catch anything at all. Measured
     | 2026-08-11: with it the candidate fails under Santiago too, without it the
     | zone ran the whole suite green without ever touching its own transition.
     | Delete that case and this zone goes quiet without a single test turning
     | red.
     */
    'America/Havana',
    'America/Santiago',
];

const results = [];

for (const zone of zones) {
    const run = spawnSync(process.execPath, ['--test', testFile], {
        env: {...process.env, TZ: zone},
        encoding: 'utf8',
    });

    // node --test's summary lines look like "ℹ pass 60" / "ℹ fail 2".
    const tally = (word) => (run.stdout.match(new RegExp(`^\\S*\\s*${word} (\\d+)$`, 'm')) ?? [, '?'])[1];
    const pass = tally('pass');
    const fail = tally('fail');
    const ok = run.status === 0;

    results.push({zone, ok, pass, fail});

    process.stdout.write(`\n===== TZ=${zone} =====\n`);
    process.stdout.write(ok ? `pass ${pass}, fail ${fail}\n` : run.stdout);
    if (run.stderr) process.stderr.write(run.stderr);
}

const width = Math.max(...zones.map((z) => z.length));
process.stdout.write('\n===== zone matrix =====\n');
for (const {zone, ok, pass, fail} of results) {
    process.stdout.write(`${ok ? 'PASS' : 'FAIL'}  ${zone.padEnd(width)}  pass ${pass}, fail ${fail}\n`);
}

const broken = results.filter((r) => !r.ok);
process.stdout.write(`\n${results.length - broken.length}/${results.length} zones green\n`);
process.exit(broken.length === 0 ? 0 : 1);
