<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet"/>

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans text-gray-900 antialiased">
<div id="hideForMobile"  class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100 dark:bg-gray-900">
    <div>
        <a href="/" wire:navigate>
            <x-application-logo class="w-24 h-24 fill-current text-gray-500"/>
        </a>
    </div>

    <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white dark:bg-gray-800 shadow-md overflow-hidden sm:rounded-lg">
        {{ $slot }}
    </div>
</div>
<div id="showForMobile" style="display: none">
    This tool does not support mobile devices. Please use a device with a larger screen.
</div>
@livewireScriptConfig
<script>
    if (window.matchMedia("(max-width: 768px)").matches || window.matchMedia('(pointer: coarse)').matches) {
        // hideForMobile ausblenden
        document.getElementById('hideForMobile').style.display = 'none';
        // showForMobile einblenden
        document.getElementById('showForMobile').style.display = 'block';
    }
</script>
</body>
</html>
