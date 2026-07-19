<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav x-data="{ open: false }" class="sticky top-0 z-40 bg-navy-50/85 dark:bg-navy-950/80 backdrop-blur border-b border-navy-100 dark:border-white/5">

    <!-- Primary Navigation Menu -->
    <div class="mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo + wordmark -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ url('/calendar') }}" wire:navigate class="flex items-center gap-2.5 group">
                        <x-application-logo class="block h-8 w-auto text-navy-900 dark:text-navy-50"/>
                        <span class="hidden sm:flex flex-col leading-none">
                            <span class="font-display text-sm font-bold tracking-tight text-navy-900 dark:text-navy-50">Perpetual Traveler</span>
                            <span class="eyebrow text-[0.625rem] text-navy-400 dark:text-navy-300 mt-0.5">Days &middot; Countries &middot; Stays</span>
                        </span>
                    </a>
                </div>

                <!-- Navigation Links (Desktop) -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <a href="{{ url('/calendar') }}" wire:navigate
                       class="inline-flex items-center px-1 pt-1 border-b-2 border-gold-400 text-sm font-semibold leading-5 text-navy-900 dark:text-navy-50 transition duration-150 ease-in-out">
                        {{ __('Calendar') }}
                    </a>
                    <a target="_blank" href="https://github.com/HolgerHatGarKeineNode/perpetual-traveler"
                       class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-navy-500 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:border-navy-200 dark:hover:border-white/20 transition duration-150 ease-in-out">
                        Source
                    </a>
                    <a href="http://lws4dd2sd7gbgfzi5npwrzsfipsaamajwj6srmdvhjkwmiygoqm3isqd.onion/"
                       class="inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-navy-500 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:border-navy-200 dark:hover:border-white/20 transition duration-150 ease-in-out">
                        Tor Onion
                    </a>
                </div>
            </div>

            <!-- Right side: Desktop links + Mobile Hamburger -->
            <div class="flex items-center gap-1">
                <a href="{{ route('profile') }}" wire:navigate
                   class="hidden sm:inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium text-navy-600 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 transition duration-150 ease-in-out">
                    {{ __('Profile') }}
                </a>
                <button wire:click="logout"
                        class="hidden sm:inline-flex items-center px-3 py-2 rounded-lg text-sm font-medium text-navy-600 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 transition duration-150 ease-in-out">
                    {{ __('Log Out') }}
                </button>

                <!-- Hamburger -->
                <div class="-me-2 flex items-center sm:hidden">
                    <button @click="open = ! open"
                            :aria-expanded="open"
                            aria-label="Menu"
                            class="inline-flex items-center justify-center p-3 rounded-lg text-navy-500 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-400 transition duration-150 ease-in-out">
                        <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                            <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex"
                                  stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M4 6h16M4 12h16M4 18h16"/>
                            <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden"
                                  stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden border-t border-navy-100 dark:border-white/5">
        <div class="pt-2 pb-3 space-y-1">
            <a href="{{ url('/calendar') }}" wire:navigate
               class="block w-full ps-3 pe-4 py-3 border-l-4 border-gold-400 text-start text-base font-semibold text-navy-900 dark:text-navy-50 bg-gold-400/10 focus:outline-none transition duration-150 ease-in-out">
                {{ __('Calendar') }}
            </a>
            <a target="_blank" href="https://github.com/HolgerHatGarKeineNode/perpetual-traveler"
               class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-navy-600 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 hover:border-navy-200 focus:outline-none transition duration-150 ease-in-out">
                Source Code (GitHub)
            </a>
            <a href="http://lws4dd2sd7gbgfzi5npwrzsfipsaamajwj6srmdvhjkwmiygoqm3isqd.onion/"
               class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-navy-600 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 hover:border-navy-200 focus:outline-none transition duration-150 ease-in-out break-all">
                Tor Onion
            </a>
        </div>

        <!-- Account -->
        <div class="pt-4 pb-2 border-t border-navy-100 dark:border-white/5">
            <div class="px-4">
                <div class="font-medium text-base text-navy-900 dark:text-navy-100"
                     x-data="{{ json_encode(['name' => auth()->user()?->name ?? '']) }}"
                     x-text="name"
                     x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="eyebrow text-navy-400 dark:text-navy-300 mt-1 break-all">{{ auth()->user()?->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <a href="{{ route('profile') }}" wire:navigate
                   class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-navy-600 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 hover:border-navy-200 focus:outline-none transition duration-150 ease-in-out">
                    {{ __('Profile') }}
                </a>
                <button wire:click="logout"
                        class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-navy-600 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 hover:border-navy-200 focus:outline-none transition duration-150 ease-in-out">
                    {{ __('Log Out') }}
                </button>
            </div>
        </div>
    </div>
</nav>
