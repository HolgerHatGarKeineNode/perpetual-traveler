<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

/**
 * One page of the console crawl (tests/Browser/RouteConsoleTest.php): open it
 * through the latch, wait for it to settle, make every Livewire component on it
 * talk to the server once, and report everything the page said — console,
 * uncaught errors, rejections, failed resources, off-origin requests and every
 * response >= 400 — plus where the browser actually ended up.
 *
 * It lives here and not in the test file so that the positive controls call the
 * SAME code path the crawl does: a control that exercised a private copy would
 * prove the copy.
 */
final class RouteCrawl
{
    /**
     * @param  string  $inject  JavaScript run in the page after the first load, before the roundtrip
     * @return array{path: string, livewire: int, first: array<int, array{channel: string, message: string}>, roundtrip: array<int, array{channel: string, message: string}>}
     */
    public static function probe(string $url, int $width, int $height, string $inject = ''): array
    {
        $latch = ConsoleLatch::open($url, $width, $height);

        // The document is complete when open() returns (goto waits for load);
        // the frames let Alpine/Livewire boot and ResizeObserver deliver, which
        // is where the errors of this app have been thrown (CalendarConsoleTest).
        $latch->settle(30);

        if ($inject !== '') {
            $latch->evaluate($inject);
            $latch->settle(10);
        }

        $first = $latch->drain();

        // THE ROUNDTRIP. A real Livewire request per component, through the same
        // fetch path a user's interaction takes; a 500 on it reaches no console.
        // Pages without a component (faq) have nothing to refresh and say so
        // through `livewire` = 0 rather than pretending.
        $livewire = (int) $latch->evaluate(<<<'JS'
        async () => {
            const components = window.Livewire ? window.Livewire.all() : [];
            // allSettled: a roundtrip answered 500 REJECTS $refresh(), and that
            // must come back as an `http` entry from the latch, not as an
            // exception that aborts the probe with no report at all.
            await Promise.allSettled(components.map((component) => component.$wire.$refresh()));

            return components.length;
        }
        JS);

        $latch->settle(10);

        return [
            'path' => (string) $latch->evaluate('() => location.pathname'),
            'livewire' => $livewire,
            'first' => $first,
            'roundtrip' => $latch->drain(),
        ];
    }

    /**
     * @param  array<int, array{channel: string, message: string}>  $entries
     */
    public static function describe(array $entries): string
    {
        return implode(' | ', array_map(
            fn (array $entry): string => '['.$entry['channel'].'] '.$entry['message'],
            $entries,
        ));
    }
}
