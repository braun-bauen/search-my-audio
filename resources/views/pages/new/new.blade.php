<div x-data="uploadHandler">
    <flux:heading size="xl" level="1">Create a new search</flux:heading>
    <flux:subheading>Upload audio files to search their transcripts.</flux:subheading>

    <form wire:submit.prevent="submitFiles" class="mt-12">
        <div class="grid grid-cols-1 gap-16 lg:grid-cols-3">
            <div class="row-start-2 lg:col-span-2 lg:row-span-2">
                <flux:field>
                    @if (Auth::user()->subscribed())
                        <input
                            hidden
                            multiple
                            type="file"
                            accept="audio/*"
                            id="fileInput"
                            @change="onFileInputChanged"
                        />
                    @else
                        <input hidden type="file" accept="audio/*" id="fileInput" @change="onFileInputChanged" />
                    @endif

                    <flux:label
                        for="fileInput"
                        @dragover.prevent="handleDragOver"
                        @dragleave.prevent="handleDragLeave"
                        @drop.prevent="onFileDropped"
                    >
                        <flux:card
                            class="w-full border-2 border-dashed p-12 text-center transition-colors lg:px-16 lg:py-28"
                        >
                            <flux:text class="pointer-events-none">
                                <span class="font-bold underline">Choose</span>
                                or drop audio files
                            </flux:text>
                        </flux:card>
                    </flux:label>

                    <flux:error name="uploadedFiles" />
                </flux:field>

                <div class="mt-10 flex flex-row flex-wrap items-end justify-between gap-2">
                    <div>
                        <flux:heading>
                            <span x-text="successCount"></span>
                            <span>of</span>
                            <span x-text="localFiles.length"></span>
                            <span x-text="localFiles.length === 1 ? ' File ' : ' Files '"></span>
                            Uploaded
                            <template x-if="failureCount > 0">
                                <span>
                                    <span>-</span>
                                    <span class="text-red-400">
                                        <span x-text="failureCount"></span>
                                        <span x-text="failureCount === 1 ? ' File ' : ' Files '"></span>
                                        Failed
                                    </span>
                                </span>
                            </template>
                        </flux:heading>
                        <flux:subheading>Invalid files will NOT be uploaded.</flux:subheading>
                    </div>

                    <flux:button
                        inset="top bottom"
                        x-cloak
                        x-show="localFiles.length > 0"
                        size="sm"
                        @click="clear"
                        label="Remove all files from upload queue"
                        variant="ghost"
                    >
                        Clear All
                    </flux:button>
                </div>

                <ul class="mt-6 flex flex-col gap-2">
                    <template x-if="localFiles.length === 0">
                        <li>
                            <flux:callout inline>
                                <flux:callout.heading class="self-center !opacity-50">
                                    0 files uploaded
                                </flux:callout.heading>
                            </flux:callout>
                        </li>
                    </template>
                    <template x-if="localFiles.length > 0">
                        <template x-for="(file, index) in localFiles" :key="index">
                            <li>
                                <flux:callout inline>
                                    <flux:callout.heading
                                        class="break-all"
                                        x-text="file.name"
                                    ></flux:callout.heading>

                                    <template x-if="file.error">
                                        <flux:callout.text>
                                            <span x-text="file.errorMessage"></span>
                                            This file will NOT be uploaded.
                                        </flux:callout.text>
                                    </template>

                                    <x-slot name="controls" class="flex flex-row items-center gap-2">
                                        <template x-if="!file.uploaded && !file.error">
                                            <flux:icon.loading variant="micro" />
                                        </template>
                                        <template x-if="file.uploaded">
                                            <flux:icon.check variant="mini" class="text-accent" />
                                        </template>
                                        <template x-if="file.error">
                                            <flux:icon.exclamation-triangle variant="mini" class="text-red-400" />
                                        </template>
                                        <flux:button
                                            @click="remove(index)"
                                            x-bind:disabled="!file.uploaded && !file.error"
                                            icon="x-mark"
                                            size="sm"
                                            label="Remove file from upload queue"
                                            variant="subtle"
                                        ></flux:button>
                                    </x-slot>
                                </flux:callout>
                            </li>
                        </template>
                    </template>
                </ul>
            </div>
            <flux:card class="flex flex-col gap-10">
                <flux:input type="text" wire:model="query" label="Search Word or Phrase" />

                <flux:switch
                    wire:model.live="completionEmail"
                    label="Completion Email"
                    description="Receive an email when the search is complete."
                />

                <flux:button type="submit" variant="primary" x-bind:disabled="uploading">Search</flux:button>
            </flux:card>
        </div>
    </form>
</div>
