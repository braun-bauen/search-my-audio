<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800">
    <nav aria-label="Skip links">
        <ul>
            <li><a href="#navigation" class="sr-only" title="Skip to Navigation">Skip to Navigation</a></li>
            <li><a href="#content" class="sr-only" title="Skip to Content">Skip to Content</a></li>
        </ul>
    </nav>

    <flux:header id="navigation" container class="sticky top-0 bg-white shadow-sm shadow-gray-200 dark:bg-zinc-900 dark:shadow-zinc-900">
        @auth
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        @else
        <a href="{{ route('home') }}" class="md:hidden flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground" wire:navigate.hover>
            <x-app-logo-icon />
        </a>
        @endauth

        <a href="{{ route('home') }}" class="hidden md:flex ml-2 mr-5 items-center space-x-2 lg:ml-0" title="Home" wire:navigate.hover>
            <x-app-logo />
        </a>

        <flux:navbar class="-mb-px max-lg:hidden">
            @auth
            <flux:navbar.item icon="plus" :href="route('new')" :current="request()->routeIs('new')" wire:navigate.hover>
                {{ __('New Search') }}
            </flux:navbar.item>
            @if (auth()->user()->subscribed())
            <flux:navbar.item icon="clock" :href="route('history')" :current="request()->routeIs('history')" wire:navigate.hover>
                {{ __('History') }}
            </flux:navbar.item>
            @else
            <flux:navbar.item icon="currency-dollar" :href="route('subscribe-basic')">
                {{ __('Upgrade') }}
            </flux:navbar.item>
            @endif
            @endauth
        </flux:navbar>

        <flux:spacer />

        <!-- Desktop User Menu -->
        @auth
        <flux:dropdown position="top" align="end">
            <flux:profile
                class="cursor-pointer"
                :initials="auth()->user()->initials()" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                            <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                <span
                                    class="flex h-full w-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                    {{ auth()->user()->initials() }}
                                </span>
                            </span>

                            <div class="grid flex-1 text-left text-sm leading-tight">
                                <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item href="/settings/profile" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                        {{ __('Log Out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
        @else
        <flux:button size="sm" variant="ghost" class="mr-4" :href="route('login')" wire:navigate.hover>
            {{ __('Log In') }}
        </flux:button>
        <flux:button size="sm" variant="primary" :href="route('register')" wire:navigate.hover>
            {{ __('Sign Up') }}
        </flux:button>
        @endauth
    </flux:header>

    <!-- Mobile Menu -->
    @auth
    <flux:sidebar stashable sticky class="lg:hidden border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <a href="{{ route('home') }}" class="ml-1 flex items-center space-x-2" wire:navigate>
            <x-app-logo />
        </a>

        <flux:navlist variant="outline">
            <flux:navlist.item icon="plus" :href="route('new')" :current="request()->routeIs('dashboard')" wire:navigate>
                {{ __('New Search') }}
            </flux:navlist.item>
            @if (auth()->user()->subscribed())
            <flux:navbar.item icon="clock" :href="route('history')" :current="request()->routeIs('history')" wire:navigate>
                {{ __('History') }}
            </flux:navbar.item>
            @else
            <flux:navbar.item icon="currency-dollar" :href="route('subscribe-basic')">
                {{ __('Upgrade') }}
            </flux:navbar.item>
            @endif
        </flux:navlist>
    </flux:sidebar>
    @endauth

    {{ $slot }}

    @fluxScripts
</body>

</html>
