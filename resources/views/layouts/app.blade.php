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
<body class="font-sans antialiased">
<div id="hideForMobile" class="min-h-screen bg-gray-100 dark:bg-gray-900">
    <livewire:layout.navigation/>

    <!-- Page Heading -->
    @if (isset($header))
        <header class="bg-white dark:bg-gray-800 shadow">
            <div class="mx-auto py-2 px-4 sm:px-6 lg:px-8">
                {{ $header }}
            </div>
        </header>
    @endif

    <!-- Page Content -->
    <main>
        {{ $slot }}
    </main>
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
