<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <h2 class="font-display font-bold text-xl text-navy-900 dark:text-navy-50 leading-tight">
                {{ __('Profile') }}
            </h2>
            <span class="eyebrow text-navy-400 dark:text-navy-300">Account &amp; keys</span>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-5 sm:p-8 bg-white dark:bg-navy-900 border border-navy-100 dark:border-white/10 rounded-2xl shadow-sm">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            <div class="p-5 sm:p-8 bg-white dark:bg-navy-900 border border-navy-100 dark:border-white/10 rounded-2xl shadow-sm">
                <div class="max-w-xl">
                    <livewire:profile.update-password-form />
                </div>
            </div>

            <div class="p-5 sm:p-8 bg-white dark:bg-navy-900 border border-risk/20 dark:border-risk-bright/20 rounded-2xl shadow-sm">
                <div class="max-w-xl">
                    <livewire:profile.delete-user-form />
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
