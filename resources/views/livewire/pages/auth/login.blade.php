<?php

use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Session;

use App\Livewire\Forms\LoginForm;

use function Livewire\Volt\layout;
use function Livewire\Volt\form;
use function Livewire\Volt\mount;

layout('layouts.guest');

form(LoginForm::class);

$submitLogin = function () {
    $this->validate();
    $this->form->authenticate();
    Session::regenerate();
    $this->redirect(
        session('url.intended', RouteServiceProvider::HOME),
        navigate: true
    );
};

$login = function ($pubKey) {
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
    }

    return redirect()->route('login');
};

mount(function () {
    if (str_contains($_SERVER['HTTP_USER_AGENT'], 'Tor')) {
        header('Location: http://lws4dd2sd7gbgfzi5npwrzsfipsaamajwj6srmdvhjkwmiygoqm3isqd.onion/login');
        exit;
    }
});

?>

<div x-data="nostrApp(@this)">
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')"/>

    <div class="flex flex-col space-y-4 items-center justify-end mt-4">

        <div class="py-8">
            <h1 x-data="{
                startingAnimation: { opacity: 0, scale: 4 },
                endingAnimation: { opacity: 1, scale: 1, stagger: 0.07, duration: 1, ease: 'expo.out' },
                addCNDScript: true,
                animateText() {
                    $el.classList.remove('invisible');
                    gsap.fromTo($el.children, this.startingAnimation, this.endingAnimation);
                },
                splitCharactersIntoSpans(element) {
                    text = element.innerHTML;
                    modifiedHTML = [];
                    for (var i = 0; i < text.length; i++) {
                        attributes = '';
                        if(text[i].trim()){ attributes = 'class=\'inline-block\''; }
                        modifiedHTML.push('<span ' + attributes + '>' + text[i] + '</span>');
                    }
                    element.innerHTML = modifiedHTML.join('');
                },
                addScriptToHead(url) {
                    script = document.createElement('script');
                    script.src = url;
                    document.head.appendChild(script);
                }
            }"
                x-init="
                splitCharactersIntoSpans($el);
                if(addCNDScript){
                    addScriptToHead('https://cdnjs.cloudflare.com/ajax/libs/gsap/3.11.5/gsap.min.js');
                }
                gsapInterval = setInterval(function(){
                    if(typeof gsap !== 'undefined'){
                        animateText();
                        clearInterval(gsapInterval);
                    }
                }, 5);
            "
                class="invisible block text-xl font-bold custom-font"
            >
                Perpetual Traveler - Calendar
            </h1>
        </div>

        <x-primary-button class="ms-3" @click="initNDK">
            {{ __('Nostr NIP-07 login') }}
        </x-primary-button>

        <a target="_blank" href="https://nostr.com" class="text-sm text-purple-500 hover:text-purple-500">
            {{ __('What is NIP-07 and how do I get a Nostr key?') }}
        </a>

        <div class="text-center text-xl text-gray-900 my-6">
            Login with username and password
        </div>

        <form wire:submit="submitLogin">

            <div>
                <x-input-label for="name" :value="__('Username')"/>
                <x-text-input wire:model="form.name" id="name" class="block mt-1 w-full" type="text" name="name"
                              required autofocus autocomplete="username"/>
                <x-input-error :messages="$errors->get('name')" class="mt-2"/>
            </div>

            <div class="mt-4">
                <x-input-label for="password" :value="__('Password')"/>

                <x-text-input wire:model="form.password" id="password" class="block mt-1 w-full"
                              type="password"
                              name="password"
                              required autocomplete="current-password"/>

                <x-input-error :messages="$errors->get('password')" class="mt-2"/>
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-primary-button class="ms-3">
                    {{ __('Log in') }}
                </x-primary-button>
            </div>

            <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800"
               href="{{ route('register') }}" wire:navigate>
                {{ __('Register') }}
            </a>
        </form>

        <div
            class="inline-flex items-center px-1 pt-1 text-sm font-medium leading-5 text-gray-900 dark:text-gray-100 focus:outline-none focus:border-indigo-700 transition duration-150 ease-in-out">
            <a class="underline text-purple-500"
               href="http://lws4dd2sd7gbgfzi5npwrzsfipsaamajwj6srmdvhjkwmiygoqm3isqd.onion/login">Onion/Tor</a>
        </div>

    </div>
</div>
