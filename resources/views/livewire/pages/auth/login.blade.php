<?php

use App\Livewire\Forms\LoginForm;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {
    public LoginForm $form;

    public function submitLogin(): void
    {
        $this->validate();
        $this->form->authenticate();
        Session::regenerate();
        $this->redirect(
            session('url.intended', RouteServiceProvider::HOME),
            navigate: true
        );
    }

    public function loginNostr($pubKey)
    {
        if ($pubKey) {
            $user = \App\Models\User::query()
                ->where('npub', $pubKey)
                ->firstOrCreate([
                    'npub' => $pubKey,
                ], [
                    'npub' => $pubKey,
                    'name' => str($pubKey)->limit(20, ''),
                    'password' => bcrypt(str()->random(20)),
                    'email' => str($pubKey)->limit(20, '') . '@nostr.com',
                    'email_verified_at' => now(),
                ]);
            auth()->login($user);
            Session::regenerate();
            $this->redirect(
                session('url.intended', RouteServiceProvider::HOME),
                navigate: true
            );

            return redirect()->route('login');
        }
    }
}; ?>

<div x-data="nostrApp(@this)">
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')"/>

    <div class="reveal reveal-1">
        <p class="eyebrow text-gold-600 dark:text-gold-300">Welcome back</p>
        <h1 class="mt-2 font-display text-2xl font-bold tracking-tight text-navy-900 dark:text-navy-50">
            Log in to your calendar
        </h1>
    </div>

    {{-- Nostr login — the key-based path, in its own identity colour --}}
    <div class="mt-6 reveal reveal-2">
        <button type="button" @click="initNDK"
                class="w-full inline-flex items-center justify-center gap-2 min-h-[48px] px-5 py-3 rounded-lg font-semibold text-sm text-white bg-nostr-500 hover:bg-nostr-600 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-nostr-400 transition">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M7 11a5 5 0 1 1 4 4.9V22H7v-2H5v-2h2v-1.1A5 5 0 0 1 7 11Z" fill="currentColor"/>
            </svg>
            {{ __('Continue with Nostr key') }}
        </button>
        <a target="_blank" href="https://nostr.com"
           class="mt-2 inline-block text-xs text-nostr-500 dark:text-nostr-400 hover:underline">
            {{ __('What is NIP-07 and how do I get a Nostr key?') }}
        </a>
    </div>

    {{-- Divider --}}
    <div class="flex items-center gap-3 my-6" aria-hidden="true">
        <div class="h-px flex-1 bg-navy-100 dark:bg-white/10"></div>
        <span class="eyebrow text-navy-400 dark:text-navy-300">or password</span>
        <div class="h-px flex-1 bg-navy-100 dark:bg-white/10"></div>
    </div>

    <form wire:submit="submitLogin" class="w-full reveal reveal-3">
        <div>
            <x-input-label for="name" :value="__('Username')"/>
            <x-text-input wire:model="form.name" id="name" class="block mt-2 w-full" type="text" name="name"
                          required autocomplete="username"/>
            <x-input-error :messages="$errors->get('name')" class="mt-2"/>
        </div>

        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')"/>

            <x-text-input wire:model="form.password" id="password" class="block mt-2 w-full"
                          type="password"
                          name="password"
                          required autocomplete="current-password"/>

            <x-input-error :messages="$errors->get('password')" class="mt-2"/>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 mt-6">
            <a class="text-sm font-medium text-navy-500 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 rounded-md focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 text-center sm:text-left"
               href="{{ route('register') }}" wire:navigate>
                {{ __('Create an account') }}
            </a>
            <x-primary-button class="w-full sm:w-auto justify-center">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>

    <div class="mt-6 pt-5 border-t border-navy-100 dark:border-white/10 text-center">
        <a class="eyebrow text-navy-400 dark:text-navy-300 hover:text-nostr-500 dark:hover:text-nostr-400 transition"
           href="http://lws4dd2sd7gbgfzi5npwrzsfipsaamajwj6srmdvhjkwmiygoqm3isqd.onion/login">
            Prefer Tor? Open the .onion
        </a>
    </div>
</div>
