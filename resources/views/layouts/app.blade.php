<x-layouts.app.header :title="$title ?? null">
    <flux:main container id="content" class="!py-8 !lg:py-12">
        {{ $slot }}
    </flux:main>

    @persist('toast')
    <flux:toast />
    @endpersist

    <flux:button
        variant="primary"
        icon="chevron-up"
        title="Scroll to the top of the page"
        href="#content"
        class="!fixed right-0 top-5/6 rounded-r-none -mr-1"
    ></flux:button>
</x-layouts.app.header>
