<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        @stack('styles')
    </head>
    <body class="min-h-screen bg-gray-50 dark:bg-zinc-900">

        <!-- Top Navigation -->
        <flux:header container class="border-b border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-900 shadow-sm">

            <!-- Logo -->
            <a href="{{ route('books.index') }}" class="flex items-center gap-2 mr-4 lg:mr-8" wire:navigate>
                <x-app-logo-icon class="size-7 fill-current text-blue-500" />
                <span class="hidden lg:inline text-sm font-semibold tracking-tight text-gray-900 dark:text-white">StoryVel</span>
            </a>

            <!-- Primary Nav -->
            <flux:navbar class="-mb-px max-lg:hidden">
                <flux:navbar.item
                    icon="book-open-text"
                    :href="route('books.index')"
                    :current="request()->routeIs('books.*') && ! request()->routeIs('books.recently-deleted*')"
                    wire:navigate
                >
                    {{ __('My Stories') }}
                </flux:navbar.item>

                <flux:navbar.item
                    icon="pencil-square"
                    :href="route('writer.create')"
                    :current="request()->routeIs('writer.*')"
                    wire:navigate
                >
                    {{ __('Create') }}
                </flux:navbar.item>

                <flux:navbar.item
                    icon="squares-2x2"
                    :href="route('templates.index')"
                    :current="request()->routeIs('templates.*')"
                    wire:navigate
                >
                    {{ __('Templates') }}
                </flux:navbar.item>

                <flux:navbar.item
                    icon="light-bulb"
                    :href="route('ideas.index')"
                    :current="request()->routeIs('ideas.*')"
                    wire:navigate
                >
                    {{ __('Ideas') }}
                </flux:navbar.item>

                @if (auth()->user()?->email === 'bswanson@outlook.com')
                    <flux:navbar.item
                        icon="circle-stack"
                        :href="route('admin.db')"
                        :current="request()->routeIs('admin.*')"
                        wire:navigate
                    >
                        DB
                    </flux:navbar.item>
                @endif

                <flux:navbar.item
                    icon="trash"
                    :href="route('books.recently-deleted')"
                    :current="request()->routeIs('books.recently-deleted*')"
                    wire:navigate
                >
                    {{ __('Trash') }}
                </flux:navbar.item>
            </flux:navbar>

            <flux:spacer />

            <!-- Right side icons -->
            <flux:navbar class="me-2 space-x-0.5 py-0! max-lg:hidden">
                <flux:tooltip :content="__('Search')" position="bottom">
                    <flux:navbar.item class="!h-10 [&>div>svg]:size-5" icon="magnifying-glass" href="#" :label="__('Search')" />
                </flux:tooltip>
                <flux:tooltip :content="__('Settings')" position="bottom">
                    <flux:navbar.item
                        class="!h-10 [&>div>svg]:size-5"
                        icon="cog-6-tooth"
                        :href="route('profile.edit')"
                        wire:navigate
                        :label="__('Settings')"
                    />
                </flux:tooltip>
            </flux:navbar>

            <!-- User Menu -->
            <flux:dropdown position="bottom" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    :icon-trailing="request()->is('*') ? 'chevron-down' : null"
                    class="[&_.flux-profile-trailing]:hidden lg:[&_.flux-profile-trailing]:flex"
                />
                <flux:menu>
                    <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                        <flux:avatar
                            :name="auth()->user()->name"
                            :initials="auth()->user()->initials()"
                        />
                        <div class="grid flex-1 text-start text-sm leading-tight">
                            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                        </div>
                    </div>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>

            <!-- Mobile: My Stories link -->
            <a href="{{ route('books.index') }}" wire:navigate
               class="lg:hidden flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm font-semibold text-blue-600 dark:text-blue-400">
                <svg xmlns="http://www.w3.org/2000/svg" class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                </svg>
                My Stories
            </a>
            <!-- Mobile hamburger -->
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        </flux:header>

        <!-- Mobile Sidebar -->
        <flux:sidebar collapsible="mobile" sticky class="lg:hidden border-e border-gray-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <a href="{{ route('books.index') }}" class="flex items-center gap-2" wire:navigate>
                    <x-app-logo-icon class="size-7 fill-current text-blue-500" />
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">StoryVel</span>
                </a>
                <flux:sidebar.collapse />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Navigation')">
                    <flux:sidebar.item icon="book-open-text" :href="route('books.index')" :current="request()->routeIs('books.*') && ! request()->routeIs('books.recently-deleted*')" wire:navigate>
                        {{ __('My Stories') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="trash" :href="route('books.recently-deleted')" :current="request()->routeIs('books.recently-deleted*')" wire:navigate>
                        {{ __('Trash') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="pencil-square" :href="route('writer.create')" :current="request()->routeIs('writer.*')" wire:navigate>
                        {{ __('Create') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="squares-2x2" :href="route('templates.index')" :current="request()->routeIs('templates.*')" wire:navigate>
                        {{ __('Templates') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="light-bulb" :href="route('ideas.index')" :current="request()->routeIs('ideas.*')" wire:navigate>
                        {{ __('Ideas') }}
                    </flux:sidebar.item>
                    @if (auth()->user()?->email === 'bswanson@outlook.com')
                        <flux:sidebar.item icon="circle-stack" :href="route('admin.db')" :current="request()->routeIs('admin.*')" wire:navigate>
                            DB
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="cog" :href="route('profile.edit')" wire:navigate>
                    {{ __('Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>
        </flux:sidebar>

        <!-- Page Content -->
        <main>
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>
