<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="mt-12 flex flex-col gap-24">
    <section class="flex flex-col items-center">
        <flux:heading level="1" size="xl" class="leading-24 text-7xl text-center max-w-[15ch]">
            Search and Chat with<em> Your </em>Audio Files
        </flux:heading>
        <flux:subheading class="!text-2xl text-center mt-4 max-w-[35ch]">
            Effortlessly transcribe, search, and ask questions about textual content found in your audio files.
        </flux:subheading>
        <div class="mt-8">
            @auth
            <flux:button variant="primary" :href="route('new')" wire:navigate.hover>
                {{ __('Get Started') }}
            </flux:button>
            @else
            <flux:button variant="primary" :href="route('register')" wire:navigate.hover>
                {{ __('Get Started') }}
            </flux:button>
            @endauth
        </div>
    </section>
    <!-- <section> -->
    <!--     <flux:heading level="2" size="xl" class="text-4xl text-center">What Can You Do?</flux:heading> -->
    <!--     <flux:subheading>Maybe make this a tabbed list with the features on the left as tabs and content explanation/demos on the right</flux:subheading> -->
    <!--     <div class="grid auto-fill-min-[300px] gap-6 mt-8"> -->
    <!--         <flux:card> -->
    <!--             <flux:heading size="lg" class="mb-4">Search</flux:heading> -->
    <!--             <flux:text>Find where and how many times a word or query was spoken throughout your files</flux:text> -->
    <!--                 <li>View where queries where found</li> -->
    <!--                 <li>See total count of -->
    <!--         </flux:card> -->
    <!--         <flux:card> -->
    <!--             <flux:heading size="lg"></flux:heading> -->
    <!--             <flux:text>Find where and how many times a word or query was spoken throughout your files</flux:text> -->
    <!--         </flux:card> -->
    <!--         <flux:card> -->
    <!--             <flux:heading size="lg">Search</flux:heading> -->
    <!--             <flux:text>Find where and how many times a word or query was spoken throughout your files</flux:text> -->
    <!--         </flux:card> -->
    <!--         <flux:card> -->
    <!--             <flux:heading size="lg">Search</flux:heading> -->
    <!--             <flux:text>Find where and how many times a word or query was spoken throughout your files</flux:text> -->
    <!--         </flux:card> -->
    <!--     </div> -->
    <!-- </section> -->
    <!-- <section> -->
    <!--     <flux:heading level="2" size="xl" class="text-4xl text-center">What Does it Cost?</flux:heading> -->
    <!--     <div class="flex flex-row flex-wrap justify-center gap-6 mt-8"> -->
    <!--         <flux:card class="min-w-sm"> -->
    <!--             <flux:heading size="xl">Free</flux:heading> -->
    <!--             <flux:subheading>Simple but powerful</flux:subheading> -->
    <!--             Use colors to show differences in features -->
    <!--             <ul class="mt-6"> -->
    <!--                 <li>All basic features</li> -->
    <!--                 <li>25MB per file limit</li> -->
    <!--                 <li>Search 1 file at a time</li> -->
    <!--             </ul> -->
    <!--         </flux:card> -->
    <!--         <flux:card class="min-w-sm"> -->
    <!--             <flux:heading size="xl">Premium - $10/month</flux:heading> -->
    <!--             <flux:subheading>Unleash the searching</flux:subheading> -->
    <!--             <ul class="mt-6"> -->
    <!--                 <li>Transcribe and search audio files</li> -->
    <!--                 <li>25MB per file limit</li> -->
    <!--                 <li>Search unlimited files at once</li> -->
    <!--                 <li>View search history</li> -->
    <!--                 <li>Chat about all searches</li> -->
    <!--             </ul> -->
    <!--         </flux:card> -->
    <!--     </div> -->
    <!-- </section> -->
</div>
