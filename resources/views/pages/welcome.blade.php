<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <title>StoryVel - Discover Stories</title>
    </head>
    <body class="min-h-screen bg-gray-50 dark:bg-zinc-950">
        <livewire:public-stories />
        @fluxScripts
    </body>
</html>
