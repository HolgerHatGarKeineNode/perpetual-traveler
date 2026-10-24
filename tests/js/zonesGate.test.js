import test from 'node:test';
import assert from 'node:assert/strict';
import {tally, verdict} from './zonesGate.mjs';

/*
 | The gate in zones.mjs, fed the shapes it has to tell apart. This file runs in
 | every zone of the matrix like any other (pure string work, a few ms), so its
 | cases count toward EXPECTED_PASS.
 */

const summary = ({tests = 242, pass = 242, fail = 0, cancelled = 0, skipped = 0, todo = 0} = {}) => [
    `ℹ tests ${tests}`,
    'ℹ suites 65',
    `ℹ pass ${pass}`,
    `ℹ fail ${fail}`,
    `ℹ cancelled ${cancelled}`,
    `ℹ skipped ${skipped}`,
    `ℹ todo ${todo}`,
    'ℹ duration_ms 137.9',
].join('\n');

const run = (stdout, status = 0, expectedPass = 242) => verdict({status, stdout, expectedPass});

test('a complete, plain-pass run is green', () => {
    assert.deepEqual(run(summary()).problems, []);
});

test('a file that lost cases is red even though node exits 0', () => {
    const {problems} = run(summary({tests: 230, pass: 230}));

    assert.equal(problems.length, 1);
    assert.match(problems[0], /expected 242 passing tests, got 230.*set EXPECTED_PASS in tests\/js\/zones\.mjs to 230/);
});

test('a case turned skip or todo is red even though node exits 0', () => {
    // 241 pass + 1 skipped: the count is wrong AND the skip is named.
    const skippedRun = run(summary({tests: 242, pass: 241, skipped: 1}));
    assert.ok(skippedRun.problems.some((p) => /1 test\(s\) skipped/.test(p)));

    // Even when the author "fixed" the count to match, the skip alone fails it.
    const adjusted = run(summary({tests: 242, pass: 241, skipped: 1}), 0, 241);
    assert.deepEqual(adjusted.problems.length, 1);
    assert.match(adjusted.problems[0], /skipped/);

    const todoRun = run(summary({tests: 242, pass: 241, todo: 1}), 0, 241);
    assert.match(todoRun.problems.join(), /1 test\(s\) todo/);
});

test('a cancelled or failing test is red', () => {
    assert.match(run(summary({pass: 241, fail: 1}), 1).problems.join(), /1 test\(s\) fail/);
    assert.match(run(summary({pass: 241, cancelled: 1}), 1).problems.join(), /1 test\(s\) cancelled/);
});

test('an unparseable tally is red, not "0 failures"', () => {
    const {problems, pass, fail} = run('TAP version 13\n# pass 242\n# fail 0\n', 0);

    assert.equal(pass, 242);
    assert.equal(fail, 0);
    assert.match(problems.join(), /summary line\(s\) not found for: cancelled, skipped, todo, tests/);

    assert.match(run('', 0).problems.join(), /not found for: pass, fail, cancelled, skipped, todo, tests/);
});

test('a non-zero exit status is red even with a perfect-looking tally', () => {
    assert.match(run(summary(), 1).problems.join(), /exited with status 1/);
    assert.match(run(summary(), null).problems.join(), /exited with status null/);
});

test('the summary is the LAST match, not a test title that reads like one', () => {
    const stdout = `  ✔ pass 7 (0.1ms)\nℹ pass 3\n${summary()}`;

    assert.equal(tally(stdout, 'pass'), 242);
});
