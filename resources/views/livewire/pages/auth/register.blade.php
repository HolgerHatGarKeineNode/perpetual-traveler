<?php

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component {
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'lowercase', 'unique:' . User::class, 'alpha_dash'],
            'email' => ['nullable', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ];
    }

    public function register(): void
    {
        $validated = $this->validate();
        $validated['email'] = str($validated['name'])->slug('')->lower()->append('@nostr.com');

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered($user = User::create($validated)));

        Auth::login($user);

        $this->redirect(RouteServiceProvider::HOME, navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <p class="eyebrow text-gold-600 dark:text-gold-300">Get started</p>
        <h1 class="mt-2 font-display text-2xl font-bold tracking-tight text-navy-900 dark:text-navy-50">
            Create your calendar
        </h1>
        <p class="mt-2 text-sm text-navy-500 dark:text-navy-300">
            Pick a username and a password. That&rsquo;s all it takes to start counting days.
        </p>
    </div>

    <form wire:submit="register">
        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('Username')"/>
            <x-text-input wire:model="name" id="name" class="block mt-2 w-full" type="text" name="name" required
                          autofocus autocomplete="name"/>
            <x-input-error :messages="$errors->get('name')" class="mt-2"/>
        </div>

        <!-- Email Address -->
        {{--<div class="mt-4">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="email" id="email" class="block mt-2 w-full" type="email" name="email" required autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>--}}

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')"/>

            <x-text-input wire:model="password" id="password" class="block mt-2 w-full"
                          type="password"
                          name="password"
                          required autocomplete="new-password"/>

            <x-input-error :messages="$errors->get('password')" class="mt-2"/>
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm Password')"/>

            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-2 w-full"
                          type="password"
                          name="password_confirmation" required autocomplete="new-password"/>

            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2"/>
        </div>

        <div class="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-3 mt-6">
            <a class="text-sm font-medium text-navy-500 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 rounded-md focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-400 text-center sm:text-left"
               href="{{ route('login') }}" wire:navigate>
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="w-full sm:w-auto justify-center">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>

    <div class="mt-6 pt-5 border-t border-navy-100 dark:border-white/10 text-center">
        <a class="eyebrow text-navy-400 dark:text-navy-300 hover:text-nostr-500 dark:hover:text-nostr-400 transition"
           href="http://lws4dd2sd7gbgfzi5npwrzsfipsaamajwj6srmdvhjkwmiygoqm3isqd.onion/register">
            Prefer Tor? Open the .onion
        </a>
    </div>
</div>
