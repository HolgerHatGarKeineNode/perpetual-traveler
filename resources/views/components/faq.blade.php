@props([
    /* eyebrow: the mono "system voice" label above the section */
    'eyebrow' => 'Passport control // FAQ',
    'heading' => 'Questions on arrival',
    /* items: [['q' => '…', 'a' => '…html…'], …]. Answers may contain inline
       markup (e.g. <a>, <strong>) — they are rendered as HTML, so pass trusted
       copy only. Defaults below are Perpetual-Traveler specific. */
    'items' => null,
])

@php
    // Unique id base so several accordions can live on one page without clashing.
    $uid = 'faq-' . \Illuminate\Support\Str::random(6);

    $items ??= [
        [
            'q' => 'What does Perpetual Traveler actually track?',
            'a' => 'One country per day. You mark where you were on the calendar, and over the year it adds up your days per country — plus each separate stay and the gaps between them. It is a day-counter for people whose residency depends on where they physically spend their days.',
        ],
        [
            'q' => 'What is the “PT start date” for?',
            'a' => 'It marks the day you began living as a perpetual traveler. Days before it still show up, but they are counted separately from your PT days — so a stay that started before you went nomadic does not distort your current totals.',
        ],
        [
            'q' => 'Why does it flag some gaps between stays as “tight”?',
            'a' => 'Under many re-entry and rolling-window rules, two visits to the same country count as one continuous stay if the gap between them is short. When a gap is under three weeks, the app marks it so you can see it before it costs you a day-count you were relying on. This is a signal, not tax or legal advice — check the actual rule for the country you care about.',
        ],
        [
            'q' => 'Do I need a password to sign in?',
            'a' => 'No. You sign in with your Nostr key through a browser extension (NIP-07) — no email, no password to leak. Your key is the account.',
        ],
        [
            'q' => 'Who can see my travel history?',
            'a' => 'Only you. Your stays are tied to your account and are not shown to anyone else or used to build a public profile.',
        ],
    ];
@endphp

<section {{ $attributes->merge(['class' => 'w-full']) }} aria-labelledby="{{ $uid }}-title">
    <div class="mb-8 sm:mb-10">
        @if($eyebrow)
            <p class="eyebrow text-gold-600 dark:text-gold-300">{{ $eyebrow }}</p>
        @endif
        <h2 id="{{ $uid }}-title"
            class="mt-3 font-display font-bold tracking-tight text-navy-900 dark:text-navy-50
                   text-3xl sm:text-4xl leading-[1.1]">
            {{ $heading }}
        </h2>
    </div>

    {{-- Single-open accordion: one scalar `open` holds the active index,
         so exactly one panel can be expanded at a time. --}}
    <div x-data="{ open: null }" class="border-t border-navy-100 dark:border-white/10">
        @foreach($items as $i => $item)
            <div class="faq-item">
                <h3 class="m-0">
                    <button type="button"
                            id="{{ $uid }}-trigger-{{ $i }}"
                            class="faq-trigger"
                            aria-controls="{{ $uid }}-panel-{{ $i }}"
                            :aria-expanded="open === {{ $i }} ? 'true' : 'false'"
                            @click="open = (open === {{ $i }} ? null : {{ $i }})">
                        <span class="faq-q">{{ $item['q'] }}</span>
                        <span class="faq-ind" aria-hidden="true"></span>
                    </button>
                </h3>

                <div id="{{ $uid }}-panel-{{ $i }}"
                     class="faq-panel"
                     role="region"
                     aria-labelledby="{{ $uid }}-trigger-{{ $i }}"
                     :data-open="open === {{ $i }} ? 'true' : 'false'"
                     :inert="open !== {{ $i }}"
                     :aria-hidden="open === {{ $i }} ? 'false' : 'true'">
                    <div class="faq-panel-inner">
                        <p class="faq-a">{!! $item['a'] !!}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
