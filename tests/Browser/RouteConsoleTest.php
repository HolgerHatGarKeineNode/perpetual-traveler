<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Configuration;
use Laravel\Folio\Folio;
use Livewire\Livewire;
use Tests\Browser\Support\RouteCrawl;
use Tests\Browser\Support\RouteInventory;

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
];

it('crawls every GET route the router and Folio have', function () {
    // The crawl's URLs, back in the router's notation.
    $crawled = collect(CRAWL)->flatMap(fn (array $routes) => array_keys($routes))
        ->map(fn (string $url) => $url === '/' ? '/' : ltrim(str_replace('ptr-crawl-token', '{token}', $url), '/'))
        ->all();

    $pages = RouteInventory::pages();

    // Lower bounds: an inventory that returned nothing would make the diff below empty.
    expect(count($pages))->toBeGreaterThanOrEqual(10);
    expect($pages)->toContain('login', 'calendar', 'faq');

    $missing = collect($pages)
        ->reject(fn (string $uri) => in_array($uri, $crawled, true) || array_key_exists($uri, NOT_CRAWLED))
        ->values()->all();

    expect($missing)->toBe([], 'GET routes that no console crawl covers: '.implode(', ', $missing));
});

// CONTROL (a): the framework exemption is by what a route IS, not by how its URI
// starts. The user's own /livewire-admin has to stay in the inventory; Livewire's
// real asset routes and Ignition's health check have to stay out of it.
it('exempts the framework\'s routes and not a user route that merely starts like one', function () {
    Route::get('/livewire-admin', fn () => 'admin')->middleware('web');

    $pages = RouteInventory::pages();

    expect($pages)->toContain('livewire-admin')->not->toContain('_ignition/health-check')
        ->and(collect($pages)->filter(fn (string $uri) => str_starts_with($uri, trim(Livewire::getUriPrefix(), '/').'/')))->toBeEmpty();

    // Neither crawled nor excepted, so the completeness check above would name it.
    expect(NOT_CRAWLED)->not->toHaveKey('livewire-admin');
    expect(CRAWL['guest'])->not->toHaveKey('/livewire-admin');
});

// CONTROL (b): Folio pages come from Folio's own listing, so a page in a
// subdirectory — and one under a second mount path — is in the inventory. The
// flat glob this replaced saw neither.
it('lists Folio pages in subdirectories and under further mount paths', function () {
    $root = sys_get_temp_dir().'/ptr-folio-'.bin2hex(random_bytes(4));
    mkdir($root.'/ptr-section', 0777, true);
    file_put_contents($root.'/ptr-section/deep.blade.php', '<div>deep</div>');

    try {
        Folio::path($root);

        $pages = RouteInventory::pages();
    } finally {
        unlink($root.'/ptr-section/deep.blade.php');
        rmdir($root.'/ptr-section');
        rmdir($root);
    }

    expect($pages)->toContain('ptr-section/deep', 'calendar', 'faq');
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

it('reports a GET document answered 500 or 404 — positive control for the navigation itself', function () {
    new Configuration()->timeout(20_000);

    // A page that renders fine and says nothing on any console channel: the
    // status of the DOCUMENT is the only place this fault exists.
    Route::get('/ptr-500', fn () => abort(500))->middleware('web');

    $broken = RouteCrawl::probe('/ptr-500', 1280, 800);
    expect(RouteCrawl::describe($broken['first']))->toMatch('#\[http\] document 500 http://\S+/ptr-500#');

    // A path nothing serves: Folio's catch-all answers 404.
    $missing = RouteCrawl::probe('/ptr-nope', 1280, 800);
    expect(RouteCrawl::describe($missing['first']))->toMatch('#\[http\] document 404 http://\S+/ptr-nope#');

    // And a healthy page next to them reports nothing, so the two matches above
    // are the status and not an artefact of every probe.
    expect(RouteCrawl::probe('/faq', 1280, 800)['first'])->toBeEmpty();
});
