<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Perpetual Traveler') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:400,500,600,700|space-mono:400,700&display=swap" rel="stylesheet"/>

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased text-navy-900 dark:text-navy-100">
<div class="relative min-h-screen flex flex-col justify-center items-center py-10 px-4 sm:px-6 bg-navy-50 dark:bg-navy-950 overflow-hidden">
    <div class="aurora" aria-hidden="true"></div>

    <div class="relative z-10 w-full sm:max-w-md">
        <a href="/" wire:navigate class="flex items-center justify-center gap-3 mb-8 group">
            <x-application-logo class="h-10 w-auto text-navy-900 dark:text-navy-50"/>
            <span class="flex flex-col leading-none">
                <span class="font-display text-lg font-bold tracking-tight text-navy-900 dark:text-navy-50">Perpetual Traveler</span>
                <span class="eyebrow text-navy-400 dark:text-navy-300 mt-1">Residency, tracked by the day</span>
            </span>
        </a>

        <div class="rounded-2xl border border-navy-100 dark:border-white/10 bg-white/90 dark:bg-navy-900/80 backdrop-blur px-6 py-7 sm:px-8 shadow-xl shadow-navy-900/5 dark:shadow-black/40">
            {{ $slot }}
        </div>
    </div>
</div>
@livewireScriptConfig
</body>
</html>
