<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center justify-center gap-2 min-h-[44px] px-5 py-2.5 bg-transparent border border-navy-200 dark:border-white/15 rounded-lg font-semibold text-sm text-navy-700 dark:text-navy-200 hover:bg-navy-100/60 dark:hover:bg-white/5 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 disabled:opacity-40 transition ease-out duration-150']) }}>
    {{ $slot }}
</button>
