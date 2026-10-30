<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'The Sanctuary Schools') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/branding/tss-logo.png') }}">
    @routes
    @viteReactRefresh
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased bg-slate-100 text-slate-900">
    @inertia
</body>
</html>
