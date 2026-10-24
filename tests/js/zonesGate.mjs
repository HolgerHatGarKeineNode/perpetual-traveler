/*
 | The verdict on one `node --test` run, as a pure function so that it can be
 | tested (tests/js/zonesGate.test.js) — a gate nobody ever saw go red is a
 | comment, not a gate.
 |
 | WHY THE EXIT STATUS IS NOT ENOUGH. `node --test` exits 0 for a file that
 | lost half its cases, for a case turned `.skip` or `.todo`, and — depending on
 | the reporter — for a summary line the parser no longer finds at all. Until
 | 2026-10-24 zones.mjs gated on the status alone and merely PRINTED the tally,
 | so each of those stayed green. The count is therefore pinned to an expected
 | number, and anything that is not a plain pass is a failure.
 |
 | THE REPORTER IS PINNED by the caller (`--test-reporter=spec`). Node's default
 | depends on the version and on whether stdout is a TTY — TAP when piped on
 | Node 22, spec on Node 26 — and the two print different summary lines. Pinned,
 | both versions print `ℹ pass 242` and the parser below has one format to read.
 */

/**
 * Last `<word> <n>` summary line, or null. The LAST one, because the summary is
 * printed at the end and a test whose title happens to read "pass 7" is not it.
 */
export function tally(stdout, word) {
    const matches = [...stdout.matchAll(new RegExp(`^\\S*\\s*${word} (\\d+)\\s*$`, 'gm'))];

    return matches.length ? Number(matches[matches.length - 1][1]) : null;
}

/**
 * @returns {{problems: string[], pass: number|null, fail: number|null}} no problems = green
 */
export function verdict({status, stdout, expectedPass}) {
    const pass = tally(stdout, 'pass');
    const fail = tally(stdout, 'fail');
    const cancelled = tally(stdout, 'cancelled');
    const skipped = tally(stdout, 'skipped');
    const todo = tally(stdout, 'todo');
    const tests = tally(stdout, 'tests');

    const problems = [];

    if (status !== 0) {
        problems.push(`node --test exited with status ${status}`);
    }

    const unreadable = Object.entries({pass, fail, cancelled, skipped, todo, tests})
        .filter(([, value]) => value === null)
        .map(([name]) => name);

    if (unreadable.length) {
        // An unparseable tally is NOT "zero failures": it is a run nobody read.
        problems.push(`summary line(s) not found for: ${unreadable.join(', ')} — is the reporter still spec?`);
    }

    if (pass !== null && pass !== expectedPass) {
        problems.push(
            `expected ${expectedPass} passing tests, got ${pass}. If you added or removed tests on purpose, `
            + `set EXPECTED_PASS in tests/js/zones.mjs to ${pass}; if not, a case was lost.`,
        );
    }

    for (const [name, value] of Object.entries({fail, cancelled, skipped, todo})) {
        if (value) {
            problems.push(`${value} test(s) ${name} — a skipped or todo case is a case that does not run`);
        }
    }

    if (!unreadable.length && tests !== pass + fail + cancelled + skipped + todo) {
        problems.push(`tally does not add up: tests ${tests} != pass ${pass} + fail ${fail} + cancelled ${cancelled} + skipped ${skipped} + todo ${todo}`);
    }

    return {problems, pass, fail};
}
