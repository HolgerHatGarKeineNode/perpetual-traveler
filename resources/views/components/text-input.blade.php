@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-navy-200 dark:border-white/10 bg-white dark:bg-navy-950/60 text-navy-900 dark:text-navy-100 placeholder:text-navy-400 dark:placeholder:text-navy-400 focus:border-gold-400 focus:ring-2 focus:ring-gold-400/40 rounded-lg shadow-sm px-3.5 py-2.5 text-base sm:text-sm transition']) !!}>
