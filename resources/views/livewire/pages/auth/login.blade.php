<?php

use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Session;

use function Livewire\Volt\layout;

layout('layouts.guest');

$login = function ($pubKey) {
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
};

?>

<div x-data="nostrApp(@this)">
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')"/>

    <div
            class="flex flex-col space-y-4 items-center justify-end mt-4">

        <div class="py-4">
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
                class="invisible block text-3xl font-bold custom-font"
            >
                Perpetual Traveler - Calendar
            </h1>
        </div>


        <x-primary-button class="ms-3" @click="initNDK">
            {{ __('NIP-07 login') }}
        </x-primary-button>

        <a target="_blank" href="https://nostr.com" class="text-sm text-purple-500 hover:text-purple-500">
            {{ __('What is NIP-07 and how do I get a Nostr key?') }}
        </a>
    </div>
</div>
