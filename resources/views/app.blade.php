<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>
    @vite(['resources/sass/app.scss', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="bg-body-tertiary">
    @inertia
</body>
</html>
