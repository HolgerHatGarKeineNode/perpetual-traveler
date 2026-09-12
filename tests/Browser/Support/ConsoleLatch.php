<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Enums\Device;
use Pest\Browser\Playwright\InitScript;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\Support\ComputeUrl;

/*
|--------------------------------------------------------------------------
| THE CONSOLE LATCH — everything the browser complains about, in one list
|--------------------------------------------------------------------------
|
| WHY THIS EXISTS AT ALL, given that pest-plugin-browser ships
| assertNoConsoleLogs() and assertNoJavaScriptErrors(): between them those two
| see exactly TWO things. Read Playwright/InitScript.php in the plugin — it
| wraps `console.log` and adds a BUBBLING `window` listener for 'error'. That
| is the whole recorder. Four channels a real page complains through are not
| in it:
|
|   1. console.error / console.warn. Livewire's own client reports a dead
|      component through console.error ("Snapshot missing on Livewire
|      component"); Alpine reports a broken expression the same way. Neither
|      makes a server-side test red and neither is in the plugin's list.
|   2. unhandledrejection. A rejected promise nobody caught never becomes an
|      'error' event.
|   3. resource failures (a 404 on a <script>/<img>/<link>). Those fire on the
|      ELEMENT and do not bubble, so a listener registered without the capture
|      flag — which is what the plugin registers — never sees them.
|   4. HTTP status codes. A 500 on a fetch/XHR roundtrip is a resolved
|      promise with a status, not an error of any kind: it appears in NO
|      console channel whatsoever. A Livewire page can answer every single
|      interaction with a 500 and look immaculate in every console assertion
|      there is.
|
| The latch is a BLANKET, not an allow-list: it records every console level
| that produces output, every uncaught error, every unhandled rejection,
| every failed resource and every response >= 400 — and the assertion is that
| the list is EMPTY. An allow-list of the messages we happened to see while
| writing this would, by construction, not catch the next one.
|
| WHERE THE BLANKET STOPS, and why there: console.dir/table/group/count/time
| are not wrapped. They produce output, but they are debugging aids that do
| not carry a complaint, and every wrapper here is a monkey-patch on a global
| that the page under test then runs through. Six levels plus a falsy
| console.assert is the whole complaint surface; the rest is instrumentation.
|
| WHY IT BUILDS ITS OWN CONTEXT INSTEAD OF USING visit(). The recorder has to
| be in place BEFORE the first byte of page script runs, or the errors thrown
| during Alpine's and Livewire's boot — the ones this page actually produces —
| are recorded by nobody. That means Context::addInitScript(), and an init
| script only applies to the NEXT document.
|
| There is a shorter route to the same place: visit() returns a page whose
| ->page()->context() is reachable, so one could add the script there and
| reload. It was rejected on purpose. That measures a RELOAD — the first
| navigation of the context happens with no recorder on it and is thrown away,
| and anything that only happens on a cold load (an asset that 404s once, a
| boot path that runs before a cache is warm) is thrown away with it. The
| latch's whole claim is about the first load, so the first load is what it
| watches.
|
| So this builds the context the plugin would have built — Device::DESKTOP,
| locale en-US, timezone UTC, the plugin's default colour scheme — adds the
| plugin's OWN init script first so assertNoConsoleLogs() and
| assertNoJavaScriptErrors() keep working on a page opened this way, and only
| then adds this one. Browser::newContext() registers the context with the
| browser, so Playwright::reset() in the plugin's afterEach still closes it;
| there is no teardown of our own to forget.
|
| Measured against pest-plugin-browser v5.0.1. Four of the five @internal
| imports break loudly if the plugin moves: a class that disappears is a fatal
| error, a signature that changes is a TypeError. The DEFAULTS above are the
| one part that does not — measured, not assumed: dropping
| ...Device::DESKTOP->context() from the options and running the whole file
| gives EXIT=0 and six green tests, because window.__ptrConsole is installed by
| THIS class and exists whatever the plugin does with its own defaults. A
| sentence here previously claimed the opposite.
|
| So the drift is made loud by a test instead of by a comment: the last case in
| tests/Browser/CalendarConsoleTest.php opens the same URL twice, once through
| visit() and once through this class, and compares what the two contexts
| actually look like from inside the page (locale, timezone, colour scheme,
| device pixel ratio, touch points, viewport). Neither side restates the
| other's values, so a change to the plugin's defaults separates them. A
| version pin in composer.json was the alternative and was declined: it would
| close the gap only until the next bump is taken, and the bump is coming (this
| repo moved Playwright 1.62.1 -> 1.63.0 in the same session).
|
| ONE MORE BOUNDARY, named here rather than discovered later: the entries live
| on `window`, so a full-page navigation drops them. No test navigates a latched
| page today. Rather than leave that as a comment, the recorder counts documents
| in sessionStorage and assertSilent() refuses to report on a page that has
| navigated since open() — a lost list fails, it does not read as silence.
*/
final class ConsoleLatch
{
    private function __construct(
        private readonly Page $playwrightPage,
        public readonly AwaitableWebpage $page,
    ) {
        //
    }

    /**
     * Opens the given URL at the given viewport with the recorder already installed.
     */
    public static function open(string $url, int $width, int $height): self
    {
        $browser = Playwright::browser(Playwright::defaultBrowserType())->launch();

        $context = $browser->newContext([
            'locale' => 'en-US',
            'timezoneId' => 'UTC',
            'colorScheme' => Playwright::defaultColorScheme()->value,
            ...Device::DESKTOP->context(),
            'viewport' => ['width' => $width, 'height' => $height],
        ]);

        // The plugin's recorder FIRST, so assertNoConsoleLogs() and
        // assertNoJavaScriptErrors() keep measuring on a page opened this way.
        $context->addInitScript(InitScript::get());
        $context->addInitScript(self::recorder());

        $computed = ComputeUrl::from($url);
        $playwrightPage = $context->newPage()->goto($computed);

        return new self($playwrightPage, new AwaitableWebpage($playwrightPage, $computed));
    }

    /**
     * Everything the page has complained about since the last drain.
     *
     * @return array<int, array{channel: string, message: string}>
     */
    public function drain(): array
    {
        $this->assertSameDocument();

        /** @var array<int, array{channel: string, message: string}> $entries */
        $entries = $this->playwrightPage->evaluate(
            '() => { const e = window.__ptrConsole.entries; window.__ptrConsole.entries = []; return e; }'
        );

        return $entries;
    }

    /**
     * Refuses to read a list that a navigation has already thrown away.
     *
     * The entries are held on `window`, so the SECOND document in this context
     * starts from an empty list and everything the first one said is gone. That
     * loss is indistinguishable from silence, which is the one failure mode a
     * console latch must not have. The recorder therefore counts documents in
     * sessionStorage — which survives a navigation — and this refuses anything
     * but the first. A sessionStorage that cannot be read at all leaves the
     * counter at -1 and fails here too, rather than quietly skipping the check.
     */
    private function assertSameDocument(): void
    {
        $documents = $this->playwrightPage->evaluate('window.__ptrConsole.documents');

        expect($documents)->toBe(1, sprintf(
            'The latched page is on document #%s of this context, not its first. '
            .'Console entries live on window and did not survive the navigation, '
            .'so "no messages" here would mean "the list was thrown away". Open a '
            .'new ConsoleLatch for the page you actually want to measure.',
            var_export($documents, true),
        ));
    }

    /**
     * Asserts the page has said nothing since the last drain.
     *
     * DRAINS on the way out, so a second call reports only what arrived after
     * the first one — the failure text then names the interaction that caused
     * it instead of repeating the whole history of the page.
     */
    public function assertSilent(string $moment): self
    {
        $entries = $this->drain();

        expect($entries)->toBe([], sprintf(
            'Expected the browser console to be silent %s, but the page produced %d message(s): %s',
            $moment,
            count($entries),
            implode(' | ', array_map(
                fn (array $entry): string => '['.$entry['channel'].'] '.$entry['message'],
                $entries,
            )),
        ));

        return $this;
    }

    /**
     * Reloads the latched page.
     *
     * The ONLY caller is the guard calibration in CalendarConsoleTest: a
     * navigation is precisely what assertSameDocument() refuses to report on
     * afterwards, so this exists to prove that refusal happens. Nothing that
     * wants to measure a second page should use it — open a second latch.
     */
    public function reload(): self
    {
        $this->playwrightPage->reload();

        return $this;
    }

    /**
     * Runs the given expression in the page and returns its value.
     */
    public function evaluate(string $expression): mixed
    {
        return $this->playwrightPage->evaluate($expression);
    }

    /**
     * Settles the page for the given number of animation frames.
     *
     * FRAMES, not milliseconds: everything this latch waits for (a
     * ResizeObserver delivery, the layout write it schedules) is driven by the
     * frame clock, so a frame count is the unit that does not become flaky on
     * a loaded machine — and it cannot pass a sleep() off as a measurement.
     */
    public function settle(int $frames = 60): self
    {
        $this->playwrightPage->evaluate(<<<JS
        () => new Promise((resolve) => {
          let n = 0;
          const step = () => (++n < {$frames}) ? requestAnimationFrame(step) : resolve(true);
          requestAnimationFrame(step);
        })
        JS);

        return $this;
    }

    /**
     * The recorder, as it is injected before the page's first script.
     */
    public static function recorder(): string
    {
        return <<<'JS'
        window.__ptrConsole = { entries: [], documents: -1 };

        (() => {
            // HOW MANY DOCUMENTS this context has loaded. sessionStorage is the
            // only store here that outlives a navigation, which is exactly the
            // event that empties `entries` — see assertSameDocument().
            try {
                const seen = Number(sessionStorage.getItem('__ptrDocuments') || 0) + 1;
                sessionStorage.setItem('__ptrDocuments', String(seen));
                window.__ptrConsole.documents = seen;
            } catch (error) {
                // Left at -1 on purpose: unreadable is not the same as first.
            }

            const record = (channel, message) => {
                window.__ptrConsole.entries.push({
                    channel: channel,
                    message: String(message).slice(0, 500),
                });
            };

            const describe = (value) => (value && value.stack) ? value.stack : String(value);

            for (const level of ['error', 'warn', 'log', 'info', 'debug', 'trace']) {
                const original = console[level];
                console[level] = function (...args) {
                    record('console.' + level, args.map(describe).join(' '));

                    return original.apply(console, args);
                };
            }

            // console.assert only says something when its condition is FALSY —
            // wrapping it like the levels above would record every passing
            // assertion as a complaint.
            const originalAssert = console.assert;
            console.assert = function (condition, ...args) {
                if (!condition) {
                    record('console.assert', args.map(describe).join(' '));
                }

                return originalAssert.apply(console, [condition, ...args]);
            };

            // CAPTURE PHASE. A failed <script>/<img>/<link> fires 'error' on the
            // element and does NOT bubble, so the plugin's own bubbling listener
            // cannot see it. On the capture phase both kinds arrive here, and
            // e.target tells them apart: window for a thrown error, an element
            // for a resource.
            window.addEventListener('error', (event) => {
                const target = event.target;

                if (target && target !== window && target.tagName) {
                    record('resource', target.tagName + ' ' + (target.src || target.href || ''));

                    return;
                }

                record('uncaught', event.message || describe(event.error));
            }, true);

            window.addEventListener('unhandledrejection', (event) => {
                record('unhandledrejection', describe(event.reason));
            });

            // THE CHANNEL NO CONSOLE HAS. Livewire answers every interaction on
            // this page through fetch; a 500 there resolves the promise with a
            // status and prints nothing anywhere.
            const originalFetch = window.fetch;
            window.fetch = function (...args) {
                return originalFetch.apply(this, args).then(
                    (response) => {
                        if (response.status >= 400) {
                            record('http', response.status + ' ' + response.url);
                        }

                        return response;
                    },
                    (error) => {
                        record('http', 'network failure: ' + describe(error));

                        throw error;
                    },
                );
            };

            const originalOpen = XMLHttpRequest.prototype.open;
            const originalSend = XMLHttpRequest.prototype.send;

            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__ptrUrl = url;

                return originalOpen.call(this, method, url, ...rest);
            };

            XMLHttpRequest.prototype.send = function (...args) {
                this.addEventListener('load', () => {
                    if (this.status >= 400) {
                        record('http', this.status + ' ' + this.__ptrUrl);
                    }
                });

                this.addEventListener('error', () => {
                    record('http', 'network failure: ' + this.__ptrUrl);
                });

                return originalSend.apply(this, args);
            };
        })();
        JS;
    }
}
