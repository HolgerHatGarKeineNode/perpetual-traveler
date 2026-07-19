<?php

// Public marketing FAQ page — Folio auto-routes this to /faq (no auth).

?>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>FAQ — {{ config('app.name', 'Perpetual Traveler') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|sora:400,500,600,700|jetbrains-mono:400,500,700&display=swap" rel="stylesheet"/>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased text-navy-900 dark:text-navy-100">
<div class="relative min-h-screen bg-navy-50 dark:bg-navy-950 overflow-hidden">
    <div class="aurora" aria-hidden="true"></div>

    <main class="relative z-10 mx-auto w-full max-w-3xl px-4 sm:px-6 lg:px-8 py-16 sm:py-24">
        <a href="/" wire:navigate class="inline-flex items-center gap-3 mb-14 group">
            <x-application-logo class="h-9 w-auto text-navy-900 dark:text-navy-50"/>
            <span class="flex flex-col leading-none">
                <span class="font-display text-base font-bold tracking-tight text-navy-900 dark:text-navy-50">Perpetual Traveler</span>
                <span class="eyebrow text-navy-400 dark:text-navy-300 mt-1">Residency, tracked by the day</span>
            </span>
        </a>

        <x-faq />
    </main>
</div>
@livewireScriptConfig
</body>
</html>
