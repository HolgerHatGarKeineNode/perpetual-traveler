<?php

use App\Models\User;
use Livewire\Volt\Volt;

// P14: the country-select modal had no role="dialog" / aria-modal, so a
// screen reader never announces the dialog transition, even though the
// keyboard path itself works (focus lands in the search field, Escape
// closes, the trap holds — P10/P15). Static, server-rendered markup, so a
// Volt component test is the right level: no Alpine runtime state changes
// role/aria-modal/aria-labelledby, only x-show toggles visibility on top of
// them.
test('the country-select modal announces itself as a named dialog', function () {
    $user = User::factory()->create();
    test()->actingAs($user);

    $html = Volt::test('calendar')->html();

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $dialogs = $xpath->query('//div[@role="dialog"]');

    // Exactly one dialog on the page — not zero (the bug) and not several
    // (which would make aria-labelledby ambiguous for assistive tech).
    expect($dialogs->length)->toBe(1);

    $dialog = $dialogs->item(0);
    expect($dialog->getAttribute('aria-modal'))->toBe('true');

    $labelledBy = $dialog->getAttribute('aria-labelledby');
    expect($labelledBy)->not->toBe('');

    // The reference must resolve to a real, unique element carrying the
    // heading a sighted user actually sees — not a decorative node and not a
    // dangling id.
    $labelNodes = $xpath->query('//*[@id="' . $labelledBy . '"]');
    expect($labelNodes->length)->toBe(1);
    expect(trim($labelNodes->item(0)->textContent))->toBe('Choose country');
});
