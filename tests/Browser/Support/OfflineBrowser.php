<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| NO BROWSER TEST LEAVES THE MACHINE — the fonts stylesheet, answered locally
|--------------------------------------------------------------------------
|
| layouts/app, layouts/guest and pages/faq link a Bunny Fonts stylesheet
| (https://fonts.bunny.net/css?family=inter…). Every page load in the browser
| suite therefore made a real HTTPS request to a third party, and the suite's
| result depended on that host's mood. Measured 2026-10-24, same code, same
| machine:
|
|   - the first open of /faq took 7.5 s (a warm one takes 1.4 s) and a
|     fixed-timeout test on it failed after 16.9 s;
|   - 2 of 3 full browser runs went red; the one whose message was captured
|     read "[resource] LINK https://fonts.bunny.net/css?family=inter:400,…" —
|     the stylesheet's error event, thrown by a page that was otherwise silent
|     (the other red run's message was not captured, so it is not claimed).
|
| The latch was right to be red: the page DID complain. The fault is in the
| test, which let the page depend on the internet.
|
| THE FIX IS AT THE SERVER, because that is the only lever there is. The
| plugin launches Chromium with a fixed options object and its Context offers
| no route() — the same wall tests/Browser/Support/BrowserClock.php hit. The
| in-process server answers through the app's own HTTP kernel, so a global
| middleware (installed per test, from tests/Pest.php) can do two things:
|
|   1. rewrite the Bunny host in HTML responses to this server's own
|      /__offline/ prefix, and
|   2. answer that prefix with an empty stylesheet.
|
| WHAT THIS CHANGES ABOUT WHAT IS MEASURED, stated rather than left to be
| found: the page renders in its fallback fonts, not Inter/Sora/JetBrains Mono.
| That is the same thing the suite measured on any machine without a route to
| Bunny, and now it is the same thing on every machine. Layout assertions that
| depend on glyph widths are measured in the fallback font; none of the 16
| browser tests changed result.
|
| IT IS NOT AN ALLOW-LIST FOR THE NEXT HOST. Only the one known host is
| rewritten. Any other off-origin request is reported by the console latch on
| its `external` channel (ConsoleLatch::recorder()), so a new CDN link makes the
| suite red instead of quietly making it slow.
*/
final class OfflineBrowser
{
    public const HOST = 'https://fonts.bunny.net';

    public const PREFIX = '/__offline';

    /**
     * Idempotent: Kernel::pushMiddleware() ignores a class it already has.
     */
    public static function install(): void
    {
        app(Kernel::class)->pushMiddleware(self::class);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (str_starts_with($request->getPathInfo(), self::PREFIX.'/')) {
            return response('/* served locally: tests/Browser/Support/OfflineBrowser.php */', 200, [
                'Content-Type' => 'text/css; charset=UTF-8',
            ]);
        }

        $response = $next($request);

        $isHtml = str_contains((string) $response->headers->get('Content-Type'), 'text/html');
        $content = $response->getContent();

        if ($isHtml && is_string($content) && str_contains($content, self::HOST)) {
            $response->setContent(str_replace(self::HOST, $request->getSchemeAndHttpHost().self::PREFIX, $content));
        }

        return $response;
    }
}
