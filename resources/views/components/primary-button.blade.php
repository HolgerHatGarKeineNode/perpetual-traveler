<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-2 min-h-[44px] px-5 py-2.5 bg-gold-400 hover:bg-gold-300 active:bg-gold-500 border border-transparent rounded-lg font-semibold text-sm text-navy-950 shadow-sm shadow-gold-500/20 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 transition ease-out duration-150']) }}>
    {{ $slot }}
</button>
