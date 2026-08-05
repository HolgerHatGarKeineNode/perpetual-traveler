# Perpetual Traveler - Calendar

### LIVE

Clearnet: https://pt.codingarena.top

### Description

A Perpetual Traveler's calendar. This is a simple calendar app that allows you to keep track of your travels. It's
designed to be used by perpetual travelers, but it can be used by anyone who wants to keep track of their travels. It's
a simple app that allows you to add and delete events. It also allows you to view your events in a calendar view.

![Screenshot](https://affekt-assets.s3.amazonaws.com/share/pt/pt_2024-02-07_16-10-57.jpg)

You can sign in with Nostr NIP-07. Also, there is a username-password login.

![Screenshot](https://affekt-assets.s3.amazonaws.com/share/pt/pt_2024-02-06_20-28-16.jpg)

## Development

### Requirements

- PHP >= 8.2
- Composer
- Node.js + Yarn

### Initial setup

```sh
cp .env.example .env
composer install
yarn install
touch database/database.sqlite
php artisan key:generate
php artisan migrate
```

### Starting the dev server

```sh
composer run dev
```

This runs `php artisan serve`, `php artisan queue:listen` and `yarn dev` in parallel via `concurrently`.

### Running tests

```sh
composer test              # full suite, replays unchanged tests from the TIA graph
composer test:tia          # selective run WITH coverage driver — this is what records/updates the graph
composer test:tia:fresh    # same, but discards the graph and records it from scratch
php artisan test --no-tia  # plain run, TIA switched off
```

Notes:

- **Coverage is not enabled system-wide.** `/etc/php/conf.d/xdebug.ini` keeps `xdebug.mode=off`
  (a system-wide `coverage` mode costs roughly 14x on *every* PHP CLI call on this machine). The
  `test:tia*` scripts switch it on per run via `XDEBUG_MODE=coverage`.
- Consequence: only `composer test:tia*` can **record** the dependency graph. `composer test`
  replays from an existing graph; without one it prints
  `Running in TIA mode, however TIA as skipped as it needs Needs ext-pcov or Xdebug.` in its **first
  output line** and runs everything. That first line is the only reliable way to tell the two
  apart — the test totals and the exit code are identical either way.
- `composer test -- --no-tia` does **not** work: the argument is passed to the first script step
  (`php artisan config:clear`), which fails with `The "--no-tia" option does not exist.` Use
  `php artisan test --no-tia` instead.
- Rector runs on `tests/` only (see `rector.php`):
  `vendor/bin/rector process tests --dry-run --clear-cache`. Without `--clear-cache` Rector
  reports `[OK] Rector is done!` from its cache — you would be looking at the previous run, not
  the current config.

### Pest 5 plugin decisions

Evaluated for **this** repo on 2026-08-02. "Bundled" means the package is already installed as a
dependency of `pestphp/pest ^5.0` — no separate `composer require` needed.

| Plugin / feature | Verdict | Reason (specific to this repo) |
| --- | --- | --- |
| `pest-plugin` | bundled | The plugin manager itself, a hard `require` of `pestphp/pest`. Listed for completeness — it is not a choice. |
| `pest-plugin-arch` | bundled | Ships with `pestphp/pest` v5.0.2. No `arch()` test exists in `tests/` yet, but the plugin is available at no extra cost. |
| `pest-plugin-browser` | recommended, not installed | The whole client layer is untested: `resources/js/nostrCal.js`, `nostrApp.js`, FullCalendar interaction, the Alpine country-picker modal and the FAQ accordion have no test at all — all 10 test files are server-side `Volt::test()`. Cost: it spawns `./node_modules/.bin/playwright run-server`, and `package.json` has no `playwright`. The Nostr NIP-07 login stays untestable either way (needs a signer extension). |
| `pest-plugin-drift` | n/a | Drift converts PHPUnit class-style tests to Pest. There are none — all 10 test files already use `test()`; the only classes under `tests/` are the `TestCase` base class and the `CreatesApplication` trait, which Drift does not convert. |
| `pest-plugin-faker` | n/a | Duplicate helper. `fakerphp/faker` is already in `require-dev` and Laravel's `fake()` is already in use (`database/factories/UserFactory.php:27-28`). The plugin only adds a second name (`faker()`) for the same thing. |
| `pest-plugin-laravel` | installed | Ships with the Laravel Breeze skeleton. Its own API — the global `Pest\Laravel\*` functions — is **not** used here: no test imports them (`grep -rn "Pest\\Laravel\|use function Pest" tests/` is empty). The `actingAs`/`get`/`assertGuest` calls in the Feature tests come from `laravel/framework` (`InteractsWithAuthentication`, `MakesHttpRequests`) via `Tests\TestCase`, not from this plugin. Kept because it is harmless and conventional, not because anything depends on it. |
| `pest-plugin-livewire` | n/a | Its `livewire()` helper is a one-line alias for `Livewire::test()`. This repo's tests need `Volt::test()`, which additionally calls `ensureViewsAreCached()` and resolves the Volt `FragmentMap` (`vendor/livewire/volt/src/VoltManager.php:67-76`) — the plugin's helper skips both and would resolve *fewer* components here, not more. |
| `pest-plugin-mutate` | bundled, currently broken | Ships with `pestphp/pest` v5.0.2 (`--mutate` is in `pest --help`) and never touches the working tree — mutants go to `vendor/pestphp/pest-plugin-mutate/.temp/mutations` and are injected through a registered stream wrapper. But v5.0.0 crashes against `phpunit/php-code-coverage` 14.2.4: `Call to a member function getData() on array` at `MutationTestRunner.php:116`, because the `--coverage-php` report is now a serialized array, not a `CodeCoverage` object. Reproduced with and without `--covered-only`. Upstream bug — re-check after a plugin update. |
| `pest-plugin-parallel` | n/a | Legacy Pest 1 package (latest v1.2.1). Its runtime constraint is `pestphp/pest-plugin ^1.1.0` plus `brianium/paratest ^6.8.1` — `pestphp/pest ^1.22.3` only appears under `require-dev` and does not affect installability. Not installable here either way. `--parallel` is part of Pest 5 core via `brianium/paratest` v7.23.1. |
| `pest-plugin-phpstan` | n/a | Requires `phpstan/phpstan ^2.2.5`. Neither PHPStan nor Larastan is in `composer.json` and there is no `phpstan.neon` — the plugin would mean adopting static analysis first, which is a separate decision. |
| `pest-plugin-profanity` | bundled | Ships with `pestphp/pest` v5.0.2 but only runs when `--profanity` is passed (`Plugin.php:62`), so it costs nothing in the normal run. |
| `pest-plugin-rector` | installed | Rewrote the 7 remaining PHPUnit-style assertions in `tests/` to Pest matchers; config in `rector.php`, scoped to `tests/`. |
| `pest-plugin-stressless` | n/a | Downloads the k6 binary from GitHub at runtime and fires real HTTP load at a URL. The only deployed URL is production (`pt.codingarena.top`); there is no staging target and no performance goal anywhere in the repo. |
| `pest-plugin-type-coverage` | n/a | Pulls in `phpstan/phpstan ^2.2.5`, same blocker as the PHPStan plugin. There is also little to find: all 24 methods in `app/` already declare return types and all 6 parameters are typed. |
| `pest-plugin-watch` | n/a | Marked **abandoned** on Packagist. The actual blocker in v3.0.0 is `pestphp/pest-plugin ^3.0.0` against our locked v5.0.0 (`pestphp/pest ^3.8.3` is only a `require-dev` entry and irrelevant to installability). Pest 5 has no `--watch` of its own (zero hits in `pest --help`); the TIA replay is the practical substitute. |
| `pest-plugin-agent` | n/a | Generates a throwaway test file from `--agent --code="…"` and deletes it again in `terminate()`. Nothing it produces ends up in the committed suite, so it adds no regression coverage to this repo. |
| `pest-plugin-evals` | n/a | LLM-agent evaluation. There is no LLM code in this project — `grep -rliE "openai\|anthropic\|claude\|gpt-\|llm" app resources` returns nothing. |
| New matchers (`toBeEmail()`, `toBeUlid()`, `toBeIpAddress()`) | n/a | No format check anywhere. The e-mail assertions are equality checks against a fixed string (`tests/Feature/ProfileTest.php:37`), which is not what `toBeEmail()` does. No ULID/UUID/IP usage in `app`, `database` or `tests`. |
| CI baseline (`.github/workflows/tia-baseline.yml`) | n/a | There is no `.github/` directory and no CI. Note for whoever builds one: `pest()->tia()->locally()` does not detect CI by itself — a CI step must pass `--ci` explicitly (`CI=true` alone is ignored). |

### Suite status

The suite is expected to end with **exit code 0** on every run (28 tests as of 2026-08-05). A red
test is a defect to be fixed, not a state to carry along; if one is meant to stay red, it needs a
named exception with a reason at the site itself.

Two long-standing defects were fixed on 2026-08-05 (`172955b`), both worth knowing about because
the failure modes recur:

- `tests/Feature/CalendarSaveDaysTest.php` addressed the calendar component by the `md5()` of the
  `@volt`…`@endvolt` body (`ExtractFragments.php:52`), so every edit to
  `resources/views/pages/calendar.blade.php` renamed the component and broke the test. The
  fragment is now named — `@volt('calendar')` — and the alias derives from name + file path
  instead of the body.
- `tests/Feature/ProfileTest.php` asserted account deletion with `expect($user->fresh())->toBeNull()`.
  `Model::fresh()` returns `null` from `$this->exists === false` alone and never queries the
  database, so the assertion stayed green even when the row survived. It now uses
  `User::find($user->id)`.
