<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route as Router;
use Livewire\Livewire;

/**
 * Every GET page the app serves, for the console crawl's completeness check
 * (tests/Browser/RouteConsoleTest.php).
 *
 * Two things were too broad before 2026-10-24 and are the reason this is a class
 * with controls instead of two filters in a test:
 *
 *  - FRAMEWORK ROUTES were exempted with str_starts_with($uri, 'livewire'), which
 *    also exempts a user's own /livewire-admin. A route is the framework's now
 *    when its ACTION is a class from a known vendor namespace, or when its URI
 *    sits under the prefix Livewire itself computes (the hashed
 *    livewire-<hash>/ — asked of Livewire, not typed here). Vendor origin of
 *    the closure is NOT used: Volt registers every page (/login, /register …)
 *    as a closure from inside vendor/livewire/volt, so "defined in vendor"
 *    would exempt the pages this crawl exists for.
 *  - FOLIO PAGES came from a flat glob of resources/views/pages/*.blade.php,
 *    blind to subdirectories and to a second mount path. They come from
 *    `php artisan folio:list --json` now, i.e. from Folio's own routing.
 */
final class RouteInventory
{
    /** Controllers from these namespaces are the framework's plumbing, not pages. */
    private const FRAMEWORK_ACTIONS = [
        'Livewire\\',
        'Spatie\\LaravelIgnition\\',
        'Laravel\\Sanctum\\',
        'Laravel\\Folio\\', // the catch-all; its pages are listed from folio:list
    ];

    public static function isFramework(Route $route): bool
    {
        $action = $route->getActionName();

        foreach (self::FRAMEWORK_ACTIONS as $namespace) {
            if (str_starts_with($action, $namespace)) {
                return true;
            }
        }

        // Closure routes of Livewire's asset transport (component css/js).
        $prefix = trim(Livewire::getUriPrefix(), '/').'/';

        return str_starts_with($route->uri(), $prefix);
    }

    /**
     * @return array<int, string> GET URIs in the router's notation (no leading slash, '/' for the root)
     */
    public static function pages(): array
    {
        $routed = collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('GET', $route->methods(), true))
            ->reject(fn (Route $route) => self::isFramework($route))
            ->map(fn (Route $route) => $route->uri());

        Artisan::call('folio:list', ['--json' => true]);

        $folio = collect(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR))
            ->map(fn (array $page) => $page['uri'] === '/' ? '/' : ltrim($page['uri'], '/'));

        return $routed->merge($folio)->unique()->sort()->values()->all();
    }
}
