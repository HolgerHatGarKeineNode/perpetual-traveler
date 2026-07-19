<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center justify-center gap-2 min-h-[44px] px-5 py-2.5 bg-risk hover:bg-risk/90 border border-transparent rounded-lg font-semibold text-sm text-white shadow-sm focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-risk transition ease-out duration-150']) }}>
    {{ $slot }}
</button>
