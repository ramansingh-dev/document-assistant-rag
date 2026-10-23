<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Document Assistant' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-screen overflow-hidden bg-stone-50 text-stone-900 antialiased dark:bg-stone-950 dark:text-stone-100">
    {{ $slot }}
    @livewireScripts
</body>
</html>
