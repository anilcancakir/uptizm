<!DOCTYPE html>
{{--
    The landing page, being rebuilt from scratch section by section.

    Right now it is an empty shell on purpose. Sections get added one at a time,
    starting with the hero; nothing goes in here that has not been looked at and
    agreed on first.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-white font-sans text-gray-900 antialiased">
        <main></main>
    </body>
</html>
