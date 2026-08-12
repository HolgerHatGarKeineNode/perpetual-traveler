<?php

/*
|--------------------------------------------------------------------------
| Host-Chromium wiring for pest-plugin-browser — idempotent, no download.
|--------------------------------------------------------------------------
|
| P2 (docs/plans/2026-08-10T2134-fullcalendar-7-umstieg.md) proved the wiring
| by hand: a Playwright chromium-<rev> registry directory with an
| INSTALLATION_COMPLETE marker and a chrome-linux64/chrome symlink onto the
| SYSTEM chromium (/usr/bin/chromium), so Playwright never falls back to
| downloading its own binary. That directory happened to pre-exist from an
| unrelated project — nothing in this repo created it, so a fresh checkout
| would have started red. This script is the missing "something", named as
| a Composer script so a fresh machine has one command to run before
| `vendor/bin/pest tests/Browser` (or `php artisan test`, since phpunit.xml
| includes the Browser suite).
|
| TWO registry entries, not one — this is the fix for a P3 review finding.
| node_modules/playwright-core/browsers.json lists "chromium" AND
| "chromium-headless-shell" as separate installByDefault entries, and
| Playwright does not pick the first one by default:
| coreBundle.js, ChromiumBrowser.getExecutableName():
|   return options.headless ? "chromium-headless-shell" : "chromium";
| Our tests never call ->headed(), so every one of them launches through the
| "chromium-headless-shell" registry entry, not "chromium". A script that
| only wires the first is wiring a browser Playwright never starts by
| default — proven the hard way: the original version of this script only
| wired "chromium", and the test only ever passed because a
| chromium_headless_shell-<rev> directory happened to already exist on this
| machine from an unrelated project (the exact "worked by accident" failure
| mode P3 exists to remove).
|
| WHAT COMES FROM browsers.json (derived, not hardcoded):
|   - the revision, per entry (they happen to match today but the script
|     does not assume that — each entry is read on its own)
|   - the registry directory name: playwright-core derives it as
|     str_replace('-', '_', $name) . '-' . $revision (coreBundle.js,
|     readDescriptors(): `browserDirectoryPrefix.replace(/-/g, "_") + "-" +
|     revision`) — that is why the ON-DISK directory is
|     "chromium_headless_shell-<rev>" (underscored) while the entry's own
|     NAME keeps the dash. Reproduced here with the same transform instead
|     of a second hardcoded string.
|
| WHAT IS HARDCODED, and why it has to be: the subdirectory/binary name
| INSIDE each registry directory (e.g. "chrome-linux64/chrome" vs.
| "chrome-headless-shell-linux64/chrome-headless-shell"). browsers.json
| carries no path information at all — that mapping lives entirely in
| playwright-core's own EXECUTABLE_PATHS table (coreBundle.js, the
| "linux-x64" rows under "chromium" and "chromium-headless-shell"), so there
| is nothing in the installed package's data files to derive it from. Pinned
| to linux-x64 deliberately: this repo's whole Host-Chromium approach is
| Linux-only already (P2), and a wrong guess here fails loudly (registry
| directory exists, executable does not) rather than silently.
|
| WHERE BOTH SYMLINKS POINT: /usr/bin/chromium for both entries. This
| system's chromium package (Arch, `pacman -Ql chromium`) ships exactly one
| browser binary — no separate chrome-headless-shell binary exists here to
| point at instead. Chrome's headless-shell is a distinct, stripped build
| optimized for headless-only startup, but the regular Chromium binary runs
| headless just as validly (`chromium --headless=old` above is not a
| PLAYWRIGHT flag, it is what proves the binary itself understands headless
| mode) and accepts the superset of the flags Playwright passes it under
| either registry name. Not assumed: `composer test` runs the full
| CalendarDragSelectTest suite against exactly this wiring as part of this
| script's own verification (see the P3 report) — a green run there is the
| proof, not the presence of the symlink.
|
| Does NOT create DEPENDENCIES_VALIDATED for either entry: Playwright's own
| registry (coreBundle.js, _validateHostRequirementsForExecutableIfNeeded)
| treats that marker as optional — absent, it just runs the validation once
| and writes the marker itself on success. Manufacturing it here would
| assert something this script never checked.
*/

$repoRoot = dirname(__DIR__);
$browsersJson = $repoRoot.'/node_modules/playwright-core/browsers.json';
$systemChromium = '/usr/bin/chromium';

// The subdirectory + binary name Playwright expects INSIDE each registry
// directory, linux-x64 only. Hardcoded because browsers.json does not carry
// this information — see the file header for the exact source line in
// playwright-core. Any "chromium"-family entry not listed here is left
// alone rather than guessed at.
const EXECUTABLE_LAYOUT = [
    'chromium' => ['chrome-linux64', 'chrome'],
    'chromium-headless-shell' => ['chrome-headless-shell-linux64', 'chrome-headless-shell'],
];

function fail(string $message): never
{
    fwrite(STDERR, "browser-setup: {$message}\n");
    exit(1);
}

if (! is_file($browsersJson)) {
    fail("{$browsersJson} not found — run \"yarn install\" first.");
}

if (! is_file($systemChromium) && ! is_link($systemChromium)) {
    fail("{$systemChromium} not found — install a system Chromium package first.");
}

$decoded = json_decode((string) file_get_contents($browsersJson), true);

if (! is_array($decoded)) {
    fail("{$browsersJson} did not parse as JSON.");
}

$revisionsByName = [];

foreach ($decoded['browsers'] ?? [] as $browser) {
    $name = $browser['name'] ?? null;

    if (is_string($name) && array_key_exists($name, EXECUTABLE_LAYOUT)) {
        $revisionsByName[$name] = $browser['revision'] ?? null;
    }
}

foreach (array_keys(EXECUTABLE_LAYOUT) as $name) {
    if (empty($revisionsByName[$name])) {
        fail("no \"{$name}\" entry found in {$browsersJson}.");
    }
}

$home = getenv('HOME');

if ($home === false || $home === '') {
    fail('the HOME environment variable is not set.');
}

foreach ($revisionsByName as $name => $revision) {
    [$subdir, $binary] = EXECUTABLE_LAYOUT[$name];

    // playwright-core's own transform (coreBundle.js, readDescriptors()):
    // the on-disk directory underscores the name, the revision is appended
    // with a dash. "chromium" has no dash to begin with, so this reduces to
    // the same "chromium-<rev>" P2 already used.
    $dirName = str_replace('-', '_', $name).'-'.$revision;

    $browserDir = $home.'/.cache/ms-playwright/'.$dirName;
    $linkDir = $browserDir.'/'.$subdir;
    $linkPath = $linkDir.'/'.$binary;
    $markerPath = $browserDir.'/INSTALLATION_COMPLETE';

    if (! is_dir($linkDir) && ! mkdir($linkDir, 0777, true) && ! is_dir($linkDir)) {
        fail("could not create {$linkDir}.");
    }

    $needsLink = true;

    if (is_link($linkPath)) {
        $needsLink = readlink($linkPath) !== $systemChromium;
    } elseif (file_exists($linkPath)) {
        fail("{$linkPath} exists and is not a symlink — refusing to overwrite it.");
    }

    if ($needsLink) {
        if (is_link($linkPath)) {
            unlink($linkPath);
        }

        if (! symlink($systemChromium, $linkPath)) {
            fail("could not symlink {$linkPath} -> {$systemChromium}.");
        }
    }

    if (! is_file($markerPath) && file_put_contents($markerPath, '') === false) {
        fail("could not write {$markerPath}.");
    }

    fwrite(STDOUT, "browser-setup: {$dirName} wired to {$systemChromium} at {$browserDir}\n");
}
