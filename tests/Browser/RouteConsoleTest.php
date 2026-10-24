<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Configuration;
use Tests\Browser\Support\RouteCrawl;

/*
|--------------------------------------------------------------------------
| THE CONSOLE ON EVERY ROUTE — and every status code, on load and on a roundtrip
|--------------------------------------------------------------------------
|
| CalendarConsoleTest latches /calendar. A console that is clean on one route
| says nothing about the next, and a 500 on an XHR never reaches the console at
| all, so this crawls the rest: every GET route the app registers, as the
| audience that can reach it, at a phone and a desktop viewport. For each page
| the latch (tests/Browser/Support/ConsoleLatch.php) must be EMPTY after the
| first load and again after every Livewire component on the page has made a
| request — empty meaning every console level, uncaught errors, rejections,
| failed resources, off-origin requests and any response >= 400. Redirects are
| fine; the page the browser ENDS on is asserted, so "logged out, bounced to
| /login, silent" cannot pass for /dashboard.
|
| THE LIST IS DERIVED, NOT TRUSTED. The first test compares it with the
| router: a GET route that exists and is in neither the crawl nor the named
| exceptions fails there, so a new page cannot ship unlatched.
|
| THE POSITIVE CONTROLS are the last two tests, and they run the crawl's own
| RouteCrawl::probe(): an injected throw has to come back in the first-load
| list, and a Livewire endpoint forced to 500 has to come back in the roundtrip
| list under channel `http`. A crawl that silently recorded nothing would pass
| everything above it.
*/

/**
 * Where each audience can go, and where the browser has to end up.
 * '/' redirects (guest -> /login, signed in -> RouteServiceProvider::HOME, /calendar).
 */
const CRAWL = [
    'guest' => [
        '/' => '/login',
        '/faq' => '/faq',
        '/login' => '/login',
        '/register' => '/register',
        '/forgot-password' => '/forgot-password',
        '/reset-password/ptr-crawl-token' => '/reset-password/ptr-crawl-token',
    ],
    'verified' => [
        '/' => '/calendar',
        '/dashboard' => '/dashboard',
        '/profile' => '/profile',
        '/calendar' => '/calendar',
        '/confirm-password' => '/confirm-password',
    ],
    'unverified' => [
        '/verify-email' => '/verify-email',
    ],
];

/** GET routes deliberately outside the crawl, with the reason. */
const NOT_CRAWLED = [
    // Needs a signed URL minted for a specific user and answers with a redirect, never a page.
    'verify-email/{id}/{hash}' => 'signed redirect, covered by tests/Feature/Auth/EmailVerificationTest.php',
    'api/user' => 'JSON endpoint behind Sanctum, not a page',
    'sanctum/csrf-cookie' => 'sets a cookie, renders nothing',
    // Folio's catch-all; the pages behind it are enumerated from resources/views/pages below.
    '{fallbackPlaceholder}' => 'Folio catch-all',
];

it('crawls every GET route the router and Folio have', function () {
    // The crawl's URLs, back in the router's notation.
    $crawled = collect(CRAWL)->flatMap(fn (array $routes) => array_keys($routes))
        ->map(fn (string $url) => ltrim(str_replace('ptr-crawl-token', '{token}', $url), '/'))
        ->all();

    $routed = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->map(fn ($route) => $route->uri())
        // Livewire's own asset transport and Ignition's health check are not pages.
        ->reject(fn (string $uri) => str_starts_with($uri, 'livewire') || str_starts_with($uri, '_ignition'))
        ->all();

    // Folio mounts resources/views/pages; its routes are not in the router.
    $folio = collect(glob(resource_path('views/pages/*.blade.php')))
        ->map(fn (string $file) => basename($file, '.blade.php'))
        ->all();

    // Lower bounds: a router or a glob that returned nothing would make the diff below empty.
    expect(count($routed))->toBeGreaterThanOrEqual(10);
    expect($folio)->toContain('calendar', 'faq');

    $missing = collect([...$routed, ...$folio])
        ->reject(fn (string $uri) => $uri === '/' ? in_array('', $crawled, true) : (in_array($uri, $crawled, true) || array_key_exists($uri, NOT_CRAWLED)))
        ->values()->all();

    expect($missing)->toBe([], 'GET routes that no console crawl covers: '.implode(', ', $missing));
});

it('keeps the console and every status code silent on every route', function (int $width, int $height) {
    new Configuration()->timeout(20_000);

    $failures = [];
    $roundtrips = 0;

    foreach (CRAWL as $audience => $routes) {
        match ($audience) {
            'guest' => null,
            'verified' => $this->actingAs(User::factory()->create()),
            'unverified' => $this->actingAs(User::factory()->unverified()->create()),
        };

        foreach ($routes as $url => $expectedPath) {
            $result = RouteCrawl::probe($url, $width, $height);

            $label = sprintf('%s %s at %dx%d', $audience, $url, $width, $height);

            if ($result['path'] !== $expectedPath) {
                $failures[] = $label.': ended on '.$result['path'].', expected '.$expectedPath;
            }

            if ($result['first'] !== []) {
                $failures[] = $label.' on first load: '.RouteCrawl::describe($result['first']);
            }

            if ($result['roundtrip'] !== []) {
                $failures[] = $label.' after the roundtrip: '.RouteCrawl::describe($result['roundtrip']);
            }

            $roundtrips += $result['livewire'];
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));

    // The roundtrip half must have happened somewhere: every page but /faq and
    // /dashboard carries a Livewire component, so zero would mean the probe
    // looked at nothing.
    expect($roundtrips)->toBeGreaterThanOrEqual(8);
})->with([
    'phone' => [390, 844],
    'desktop' => [1280, 800],
]);

it('reports a throw injected into a crawled page — positive control', function () {
    new Configuration()->timeout(20_000);

    $result = RouteCrawl::probe('/faq', 1280, 800, "() => { setTimeout(() => { throw new Error('ptr-crawl-control-throw'); }, 0); console.error('ptr-crawl-control-console'); }");

    $report = RouteCrawl::describe($result['first']);

    expect($report)
        ->toContain('[uncaught] ')
        ->toContain('ptr-crawl-control-throw')
        ->toContain('[console.error] ptr-crawl-control-console');
});

it('reports a 500 on a crawled page\'s Livewire roundtrip — positive control', function () {
    new Configuration()->timeout(20_000);

    $uri = app('router')->getRoutes()->getByName('default-livewire.update')->uri();
    Route::post($uri, fn () => response('boom', 500))->middleware('web');

    $result = RouteCrawl::probe('/login', 1280, 800);

    expect($result['livewire'])->toBeGreaterThan(0)
        ->and(RouteCrawl::describe($result['roundtrip']))->toMatch('#\[http\] 500 \S*'.preg_quote($uri, '#').'#');
});
