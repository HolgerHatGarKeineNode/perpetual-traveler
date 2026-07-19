{{--
    Perpetual Traveler signet — a border/passport stamp ring read as a globe,
    crossed by a single gold latitude: "the line you cross" (residency / border).
    Structure uses currentColor so the text-* colour at each usage site drives it
    and always contrasts; the gold latitude carries the brand and stays >=3:1 in
    both modes (gold-600 on light, gold-400 on dark). Square 40x40 viewBox, so
    w-auto + a fixed height never distorts.
--}}
<svg class="{{ $attributes->get('class') ?: 'h-8 w-auto' }}" {{ $attributes->except('class') }}
     viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg"
     role="img" aria-label="Perpetual Traveler">
    {{-- stamp ring / globe outline --}}
    <circle cx="20" cy="20" r="17.6" stroke="currentColor" stroke-width="2.4"/>
    {{-- meridian --}}
    <ellipse cx="20" cy="20" rx="7.6" ry="17.6" stroke="currentColor" stroke-width="1.5"/>
    {{-- the line you cross: residency / border latitude --}}
    <line x1="3.6" y1="20" x2="36.4" y2="20" stroke-width="2.6" stroke-linecap="round"
          class="stroke-gold-600 dark:stroke-gold-400"/>
</svg>
