<?php

use App\Models\Event;
use App\Models\User;
use Pest\Browser\Configuration;

/*
|--------------------------------------------------------------------------
| P13 — Enter stamps the country the FIELD names, not the one the page
|       happens to still be showing
|--------------------------------------------------------------------------
|
| The defect this file exists for wrote a wrong country into the database
| without saying so: the user typed "Germany", pressed Enter, and the day
| came back Algeria. `selectFirst()` asked the DOM which countries were on
| screen (`offsetParent !== null`) and took the first one — but the DOM is
| allowed to be a frame behind the search field, and by design:
|
|   x-show hands the hide to `_x_toggleAndCascadeWithTransitions`, and that
|   function defers the actual `display:none` to `requestAnimationFrame`
|   (vendor/livewire/livewire/dist/livewire.esm.js:2465 and :2487). Alpine's
|   own state — `q`, `total`, `matchName()` — is correct the instant the
|   `input` event fires. Only the rendering waits for the frame.
|
| Measured on the unfixed stand, 26 runs of CalendarKeyboardTest.php: 6 red
| (23 %). The probe below caught the state at the moment the Enter handler
| ran: q "Germany" and total 1 in every single red run, while the number of
| still-visible buttons was 4, 7 and once all 250. Stamped: `dz` twice, `af`
| once. So it was never one particular wrong country — it was whichever one
| had not been hidden yet.
|
| WHY THIS TEST DOES NOT WAIT FOR THE LIST. A test that waits would be green
| on the broken code: the bug is not that the list is slow, it is that the
| answer was read off the list at all. So the frame is not waited out, it is
| REMOVED — the `input` event and the Enter key are dispatched in the same
| task, which is the one place a `requestAnimationFrame` callback provably
| cannot have run in between. On the unfixed stand this is red every time
| (5/5, measured); on this stand it is green because the answer no longer
| comes from the page.
|
| The probe is also the negative control: it asserts that all 250 buttons
| were still on screen when Enter was handled. Without that, a future Alpine
| that hides synchronously would make this test pass while proving nothing.
*/

/**
 * Type into the country search and press Enter WITHOUT letting a frame pass.
 *
 * Returns what the page looked like at the instant the Enter handler ran —
 * the state the old implementation read its answer from.
 */
const STAMP_WITHOUT_A_FRAME = <<<'JS'
(() => {
  const inp = document.querySelector('input[aria-label="Search countries"]');
  if (!inp) return {error: 'no search field'};

  let probe = {error: 'the Enter handler never ran'};

  // Capture phase, so this sees the page BEFORE Alpine's own keydown
  // listener on the field gets the event.
  const spy = (e) => {
    if (e.key !== 'Enter') return;
    const btns = [...document.querySelectorAll('button[data-country]')];
    const d = window.Alpine.$data(inp);
    probe = {
      q: d.q,
      total: d.total,
      all: btns.length,
      visible: btns.filter(b => b.offsetParent !== null).length,
      first: (btns.find(b => b.offsetParent !== null)?.textContent ?? 'none').trim().replace(/\s+/g, ' '),
    };
  };
  document.addEventListener('keydown', spy, true);

  try {
    inp.focus();
    inp.value = 'Germany';
    inp.dispatchEvent(new Event('input', {bubbles: true}));
    inp.dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true}));
  } finally {
    document.removeEventListener('keydown', spy, true);
  }

  return probe;
})()
JS;

it('stamps the typed country even when the filtered list is still a frame behind', function () {
    new Configuration()->timeout(15_000);

    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/calendar', ['viewport' => ['width' => 1280, 'height' => 800]]);
    $page->assertPresent('td[data-date="2026-03-16"]');

    // Same route into the modal as CalendarDragSelectTest: one week, one
    // segment, a range whose days are unambiguous.
    $page->drag('td[data-date="2026-03-16"]', 'td[data-date="2026-03-18"]');
    $page->assertSee('Choose country');

    $probe = (array) $page->script(STAMP_WITHOUT_A_FRAME);

    // THE FORCED CONDITION, asserted rather than assumed: not one button had
    // been hidden when Enter was handled, although the field and Alpine's own
    // count already knew there was exactly one match.
    expect($probe['q'] ?? null)->toBe('Germany', 'the field did not take the input: '.json_encode($probe));
    expect($probe['total'] ?? null)->toBe(1, 'the state did not narrow to one match: '.json_encode($probe));
    expect($probe['all'] ?? null)->toBeGreaterThan(200, json_encode($probe));
    expect($probe['visible'] ?? null)
        ->toBe($probe['all'] ?? null, 'the list had already narrowed — the race was not forced: '.json_encode($probe));

    // And this is what the page would have answered: the first button in the
    // document, which is not the country anybody typed. (No message argument
    // here — toContain() takes further NEEDLES, not a message.)
    expect(str_contains($probe['first'] ?? '', 'Afghanistan'))
        ->toBeTrue('the first on-screen button was not the document-order first: '.json_encode($probe));

    $page->assertDontSee('Choose country');

    // THE SERVER IS THE TRUTH — same process, same in-memory connection.
    expect(Event::where('user_id', $user->id)
        ->whereIn('day', ['2026-03-16', '2026-03-17', '2026-03-18'])
        ->pluck('country')
        ->unique()
        ->all())->toBe(['de']);
});

/*
|--------------------------------------------------------------------------
| The list Enter reads and the list the user sees are ONE list
|--------------------------------------------------------------------------
|
| The fix only holds while the array handed to Alpine is in the same order as
| the buttons, because "first match" means "first in that order". Both are
| built from $grouped in the same @php block today; this pins it, so a later
| reordering of the sections cannot silently make Enter pick a country other
| than the top one on screen.
|
| No timing anywhere: visibility plays no part in this assertion, and the
| country list is teleported into the body at page load, so it is in the DOM
| whether or not the modal is open.
*/
it('holds the country data in the same order as the rendered buttons', function () {
    new Configuration()->timeout(15_000);

    $this->actingAs(User::factory()->create());

    $page = visit('/calendar', ['viewport' => ['width' => 1280, 'height' => 800]]);
    $page->assertPresent('button[data-country]');

    $order = (array) $page->script(<<<'JS'
(() => {
  const inp = document.querySelector('input[aria-label="Search countries"]');
  const d = window.Alpine.$data(inp);
  const data = d.letters.flatMap(l => (d.groups[l] || []).map(e => e.n));
  const dom = [...document.querySelectorAll('button[data-country]')]
    // "🇩🇪 Germany" — the flag is one whitespace-separated token, the name is
    // the rest, and names have spaces of their own ("United Kingdom").
    .map(b => b.textContent.trim().replace(/\s+/g, ' ').split(' ').slice(1).join(' ').toLowerCase());
  return {data, dom, codes: d.letters.flatMap(l => (d.groups[l] || []).map(e => e.c)).length};
})()
JS);

    expect(count($order['data']))->toBeGreaterThan(200);
    expect($order['codes'])->toBe(count($order['data']));
    expect($order['data'])->toBe($order['dom']);
});
