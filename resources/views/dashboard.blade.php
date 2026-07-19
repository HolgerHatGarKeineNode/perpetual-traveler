<x-app-layout>
    <x-slot name="header">
        <h2 class="font-display font-bold text-xl text-navy-900 dark:text-navy-50 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-navy-900 border border-navy-100 dark:border-white/10 overflow-hidden shadow-sm rounded-2xl">
                <div class="p-6 text-navy-900 dark:text-navy-100">
                    {{ __("You're logged in!") }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
