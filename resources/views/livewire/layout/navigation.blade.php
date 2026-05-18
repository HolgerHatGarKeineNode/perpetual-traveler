<?php

use App\Livewire\Actions\Logout;

$logout = function (Logout $logout) {
    $logout();

    $this->redirect('/', navigate: true);
};

?>

<nav x-data="{ open: false }" class="bg-white dark:bg-gray-800 border-b border-gray-100 dark:border-gray-700">

    <!-- Primary Navigation Menu -->
    <div class="mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ url('/calendar') }}" wire:navigate>
                        <x-application-logo class="block h-5 sm:h-4 w-auto fill-current text-gray-800 dark:text-gray-200"/>
                    </a>
                </div>

                <!-- Navigation Links (Desktop) -->
                <div class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex">
                    <a target="_blank" href="https://github.com/HolgerHatGarKeineNode/perpetual-traveler"
                       class="inline-flex items-center px-1 pt-1 text-sm font-medium leading-5 text-gray-900 dark:text-gray-100 hover:text-indigo-700 transition duration-150 ease-in-out">
                        Source-Code
                    </a>
                    <a href="http://lws4dd2sd7gbgfzi5npwrzsfipsaamajwj6srmdvhjkwmiygoqm3isqd.onion/"
                       class="inline-flex items-center px-1 pt-1 text-sm font-medium leading-5 text-gray-900 dark:text-gray-100 hover:text-indigo-700 transition duration-150 ease-in-out">
                        Tor Onion
                    </a>
                </div>
            </div>

            <!-- Right side: Desktop Logout + Mobile Hamburger -->
            <div class="flex items-center">
                <!-- Desktop Logout -->
                <button wire:click="logout"
                        class="hidden sm:inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-indigo-700 transition duration-150 ease-in-out">
                    {{ __('Log Out') }}
                </button>

                <!-- Hamburger -->
                <div class="-me-2 flex items-center sm:hidden">
                    <button @click="open = ! open"
                            :aria-expanded="open"
                            aria-label="Menu"
                            class="inline-flex items-center justify-center p-3 rounded-md text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:bg-gray-100 dark:focus:bg-gray-700 focus:text-gray-700 dark:focus:text-gray-200 transition duration-150 ease-in-out">
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
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <a href="{{ url('/calendar') }}" wire:navigate
               class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 focus:outline-none transition duration-150 ease-in-out">
                {{ __('Calendar') }}
            </a>
            <a target="_blank" href="https://github.com/HolgerHatGarKeineNode/perpetual-traveler"
               class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 focus:outline-none transition duration-150 ease-in-out">
                Source-Code (GitHub)
            </a>
            <a href="http://lws4dd2sd7gbgfzi5npwrzsfipsaamajwj6srmdvhjkwmiygoqm3isqd.onion/"
               class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 focus:outline-none transition duration-150 ease-in-out break-all">
                Tor Onion
            </a>
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200 dark:border-gray-600">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800 dark:text-gray-200"
                     x-data="{{ json_encode(['name' => auth()->user()?->name ?? '']) }}"
                     x-text="name"
                     x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500 break-all">{{ auth()->user()?->email }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <a href="{{ route('profile') }}" wire:navigate
                   class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 focus:outline-none transition duration-150 ease-in-out">
                    {{ __('Profile') }}
                </a>
                <button wire:click="logout"
                        class="block w-full ps-3 pe-4 py-3 border-l-4 border-transparent text-start text-base font-medium text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 hover:border-gray-300 focus:outline-none transition duration-150 ease-in-out">
                    {{ __('Log Out') }}
                </button>
            </div>
        </div>
    </div>
</nav>
