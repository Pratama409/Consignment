<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0B1220">
        <link rel="icon" type="image/svg+xml" href="{{ asset('warehouse.svg') }}">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <x-inertia::head />
    </head>
    <body>
        <x-inertia::app />
    </body>
</html>
