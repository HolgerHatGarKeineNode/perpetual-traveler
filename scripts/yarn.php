<?php

/*
|--------------------------------------------------------------------------
| Yarn launcher — runs THIS repo's Yarn, not whatever is on the PATH.
|--------------------------------------------------------------------------
|
| Yarn Berry ships its own runtime inside the repo (.yarn/releases/, pointed
| at by yarnPath in .yarnrc.yml) precisely so no global install is needed.
| Composer scripts that spelled the command as a bare "yarn" contradicted
| that: on a machine without a global yarn or corepack shim, `composer
| audit:all` exited 127 ("yarn: command not found") instead of returning an
| audit verdict — a security watch that fails silently open, which is worse
| than one that is absent. Measured 2026-09-12 on a checkout whose Node was
| 22.23.1 with no yarn on the PATH.
|
| yarnPath is READ from .yarnrc.yml rather than hardcoded, so a Yarn upgrade
| (which rewrites that line and .yarn/releases/) does not leave a second,
| stale copy of the version behind here. A global yarn stays the fallback
| for a checkout that has no bundled release.
*/

$repoRoot = dirname(__DIR__);

function fail(string $message): never
{
    fwrite(STDERR, "yarn-launcher: {$message}\n");
    exit(1);
}

$yarnPath = null;
$yarnrc = $repoRoot.'/.yarnrc.yml';

if (is_file($yarnrc)) {
    foreach (file($yarnrc, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^yarnPath:\s*"?([^"\s]+)"?\s*$/', $line, $m) === 1) {
            $yarnPath = $repoRoot.'/'.$m[1];
            break;
        }
    }
}

$args = array_slice($argv, 1);

if ($yarnPath !== null && is_file($yarnPath)) {
    $command = array_merge(['node', $yarnPath], $args);
} elseif (is_string($global = shell_exec('command -v yarn 2>/dev/null')) && trim($global) !== '') {
    $command = array_merge(['yarn'], $args);
} else {
    fail('no Yarn found — .yarnrc.yml names no usable yarnPath and no global yarn is on the PATH.');
}

$escaped = implode(' ', array_map('escapeshellarg', $command));

passthru($escaped, $exitCode);

exit($exitCode);
