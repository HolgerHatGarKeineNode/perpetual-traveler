<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Test Impact Analysis (TIA)
|--------------------------------------------------------------------------
|
| TIA waehlt anhand eines Coverage-Graphen nur die Tests aus, die von den
| geaenderten Dateien betroffen sind. "locally()" ist Pests offizielle Empfehlung
| (pestphp.com/docs/tia): TIA laeuft damit bei jedem pest-Lauf mit, ohne dass man
| --tia tippen muss — anders als "always()", das in einer kuenftigen CI ohne
| aufgezeichneten Graph mitliefe. Aus demselben Grund kein "baselined()" (es zieht
| einen Baseline-Graph aus einem CI-Artefakt).
|
| ACHTUNG, falls hier je eine CI dazukommt: "locally()" erkennt CI NICHT von
| selbst. Pest setzt die Umgebung ausschliesslich ueber das Argument --ci
| (Plugins/Environment.php), Umgebungsvariablen wie CI oder GITHUB_ACTIONS werden
| dafuer nicht gelesen — die wertet Pest nur fuer die skipOnCI()/skipLocally()-
| Helfer aus. Geprueft:
| "CI=true GITHUB_ACTIONS=true php artisan test" laeuft MIT TIA, erst
| "pest --ci" schaltet es ab. Ein CI-Schritt muss --ci also explizit mitgeben.
|
| Bewusst KEIN filtered(): Es engt den Lauf auf die betroffenen Dateien ein, dann
| meldet "composer test" nur noch die betroffenen Tests, ohne die uebersprungenen
| ueberhaupt zu nennen. Der Zeitgewinn kommt ohnehin nicht daher, sondern aus dem
| Replay der ungeaenderten Tests aus dem Graph-Cache.
|
| Bewusst KEIN watch(): Die naheliegenden Blade-Patterns stehen bereits in Pests
| eigenen Defaults — WatchDefaults/Livewire.php deckt die Blade-Dateien unter
| resources/views/livewire, /components und /pages rekursiv ab, Laravel.php
| zusaetzlich pauschal ganz resources/views. Ein eigener Eintrag dafuer waere ein
| wortgleiches Duplikat. PHP-Dateien erfasst TIA praeziser ueber den Coverage-Graph:
| Watch-Patterns sind laut Graph::applyWatchPatternFallback() nur ein Fallback
| fuer Dateien, die der Graph NICHT kennt, greifen also fuer abgedeckten Code
| ohnehin nie und wuerden fuer neue Dateien lediglich pauschal alles unter dem
| Test-Verzeichnis einplanen — das ist per Default "tests", also inklusive
| tests/Unit, nicht nur tests/Feature. Pest selbst zieht dieselbe Grenze und
| schliesst PHP in seinem app-Default ausdruecklich aus: 'app/** !*.php'.
|
*/

pest()->tia()
    ->locally();
