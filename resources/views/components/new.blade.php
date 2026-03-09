<?php

use App\Models\Search;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Title('New')] class extends Component
{
    use WithFileUploads;
    // TODO: clearing files doesn't cancel submit button loading

    #[Validate('required')]
    public $query = '';

    #[Validate('required')]
    public $completionEmail = true;

    #[Validate(
        [
            'uploadedFiles' => 'required',
        ],
        attribute: [
            'uploadedFiles.*' => 'file',
        ],
        message: [
            'uploadedFiles.*' => 'Please upload audio files.',
        ]
    )]
    public $uploadedFiles = [];

    public $canSubmit = true;
    public $fileInfo = [];

    public function addFileInfo(string $name, string $path): void
    {
        $this->fileInfo[] = [
            'name' => $name,
            'path' => $path,
        ];
    }

    public function removeFile($index)
    {
        $this->canSubmit = false;
        // Remove and Reindex arrays to avoid gaps
        unset($this->uploadedFiles[$index]);
        unset($this->fileInfo[$index]);
        $this->uploadedFiles = array_values($this->uploadedFiles);
        $this->fileInfo = array_values($this->fileInfo);
        $this->canSubmit = true;
    }

    public function clearFiles()
    {
        $this->canSubmit = false;
        $this->uploadedFiles = [];
        $this->fileInfo = [];
        $this->canSubmit = true;
    }

    public function submitFiles()
    {
        if (!Auth::user()->subscribed() && count($this->uploadedFiles) > 1) {
            Flux::toast(
                heading: 'Upload Error',
                text: 'You must be a Basic plan subscriber to upload more than one file.',
                variant: 'danger',
                duration: 10000,
            );

            return;
        }

        // Validate the form
        $this->validate();

        if ($this->canSubmit && count($this->uploadedFiles) > 0 && count($this->fileInfo) > 0) {
            $searchId = Search::createWithFiles(
                searchData: [
                    'user_id' => Auth::id(),
                    'query' => $this->query,
                    'completion_email' => $this->completionEmail,
                ],
                fileArray: $this->fileInfo,
            );

            Log::info('Created New Search: search "{query}"', ['query' => $this->query]);

            $this->redirectRoute('results', ['id' => $searchId], navigate: true);
        }
    }
}; ?>

<div x-data="uploadHandler">
    <flux:heading size="xl" level="1">Create a new search</flux:heading>
    <flux:subheading>Upload audio files to search their transcripts.</flux:subheading>

    <form wire:submit.prevent="submitFiles" class="mt-12">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-16">
            <div class="row-start-2 lg:col-span-2 lg:row-span-2">
                <flux:field>
                    @if (Auth::user()->subscribed())
                    <input hidden multiple type="file" accept="audio/*" id="fileInput" @change="onFileInputChanged" />
                    @else
                    <input hidden type="file" accept="audio/*" id="fileInput" @change="onFileInputChanged" />
                    @endif

                    <flux:label
                        for="fileInput"
                        @dragover.prevent="handleDragOver"
                        @dragleave.prevent="handleDragLeave"
                        @drop.prevent="onFileDropped">
                        <flux:card class="text-center border-dashed border-2 p-12 lg:px-16 lg:py-28 w-full transition-colors">
                            <flux:text class="pointer-events-none"><span class="underline font-bold">Choose</span> or drop audio files</flux:text>
                        </flux:card>
                    </flux:label>

                    <flux:error name="uploadedFiles" />
                </flux:field>

                <div class="flex flex-row flex-wrap gap-2 items-end justify-between mt-10">
                    <div>
                        <flux:heading>
                            <span x-text="successCount"></span>
                            <span> of </span>
                            <span x-text="localFiles.length"></span>
                            <span x-text="localFiles.length === 1 ? ' File ' : ' Files '"></span>
                            Uploaded
                            <template x-if="failureCount > 0">
                                <span>
                                    <span> - </span>
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

                    <flux:button inset="top bottom" x-cloak x-show="localFiles.length > 0" size="sm" @click="clear" label="Remove all files from upload queue" variant="ghost">
                        Clear All
                    </flux:button>
                </div>

                <ul class="mt-6 flex flex-col gap-2">
                    <template x-if="localFiles.length === 0">
                        <li>
                            <flux:callout inline>
                                <flux:callout.heading class="!opacity-50 self-center">0 files uploaded</flux:callout.heading>
                            </flux:callout>
                        </li>
                    </template>
                    <template x-if="localFiles.length > 0">
                        <template x-for="(file, index) in localFiles" :key="index">
                            <li>
                                <flux:callout inline>
                                    <flux:callout.heading class="break-all" x-text="file.name"></flux:callout.heading>

                                    <template x-if="file.error">
                                        <flux:callout.text><span x-text="file.errorMessage"></span> This file will NOT be uploaded.</flux:callout.text>
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
                                        <flux:button @click="remove(index)"
                                            x-bind:disabled="!file.uploaded && !file.error"
                                            icon="x-mark" size="sm" label="Remove file from upload queue"
                                            variant="subtle">
                                        </flux:button>
                                    </x-slot>

                                </flux:callout>
                            </li>
                        </template>
                    </template>
                </ul>
            </div>
            <flux:card class="flex flex-col gap-10">
                <flux:input type="text" wire:model="query" label="Search Word or Phrase" />

                <flux:switch wire:model.live="completionEmail" label="Completion Email" description="Receive an email when the search is complete." />

                <flux:button type="submit" variant="primary" x-bind:disabled="uploading">Search</flux:button>
            </flux:card>
        </div>
    </form>
</div>

@script
<script>
    Alpine.data('uploadHandler', () => ({
        localFiles: [],
        uploading: false,
        successCount: 0,
        failureCount: 0,

        handleDragOver(e) {
            if (!@js(Auth::user()->subscribed()) && e.dataTransfer.items.length > 1) {
                e.dataTransfer.dropEffect = 'none';
                e.target.classList.add('!border-red-400');
                Flux.toast({
                    heading: 'Selection Error',
                    text: 'Upgrade to select more than one file at a time.',
                    variant: 'danger',
                })
                return;
            }

            e.dataTransfer.dropEffect = 'move';
            e.target.classList.add('!border-accent');
        },

        handleDragLeave(event) {
            event.target.classList.remove('!border-accent', '!border-red-400');
        },

        onFileDropped(event) {
            this._addFiles(event.dataTransfer.files);
            event.target.classList.remove('!border-accent');
        },

        onFileInputChanged(event) {
            this._addFiles(event.target.files);
        },

        remove(index) {
            const file = this.localFiles.splice(index, 1)[0];
            this.$wire.removeFile(index);

            // Update success/failure counts
            if (file.error) {
                this.failureCount--;
            } else {
                this.successCount--;
            }

            // Clear file input if no files are left
            if (this.localFiles.length === 0) {
                document.querySelector('#fileInput').value = '';
            }
        },

        clear() {
            for (let i = 0; i < this.localFiles.length; i++) {
                this.$wire.cancelUpload('uploadedFiles.' + i);
            }
            this.localFiles = [];
            this.$wire.clearFiles();
            this.successCount = this.failureCount = 0;
            // Clear file input
            document.querySelector('#fileInput').value = '';
        },

        _addFiles(files) {
            if (files.length === 0) return;

            this.uploading = true;

            const fileArray = Array.from(files);

            Promise.allSettled(fileArray.map((file, index) => {
                // Add to local preview immediately
                const { index: localIndex, error } = this._initFileItem(file);

                if (error) {
                    this.failureCount++;
                    return Promise.reject("Invalid file type or size");
                }

                // Upload the file
                return this._uploadFile(localIndex, file);
            })).then(() => {
                this.uploading = false;
            }).catch(() => {
                this.uploading = false;
            });
        },

        /**
         * Creates and adds a new file item to localFiles array.
         * @param {File} file - The File object to add.
         * @returns {Object<index: number, error: boolean>}
         */
        _initFileItem(file) {
            const allowedTypes = ['audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/webm', 'audio/flac'];
            const maxFileSize = 25 * 1024 * 1024; // 25MB

            // Validate file type and size
            const isAudioFile = allowedTypes.includes(file.type) ||
                file.name.match(/\.(mp3|wav|ogg|aac|m4a|flac)$/i) !== null;
            const isValidSize = file.size <= maxFileSize;

            this.localFiles.push({
                name: file.name,
                type: file.type,
                size: file.size,
                uploaded: false,
                error: !isAudioFile || !isValidSize,
                errorMessage: !isAudioFile ?
                    'Unsupported file type.' : (!isValidSize ? 'File exceeds maximum size of 25MB' : '')
            });

            return { index: this.localFiles.length - 1, error: !isAudioFile || !isValidSize };
        },

        /**
         * Upload file and handle callbacks.
         * @param {File} file - The File object to upload.
         */
        _uploadFile(index, file) {
            return new Promise((resolve, reject) => {
                this.$wire.upload('uploadedFiles.' + index, file,
                    (uploadedFilename) => {
                        // Pass name/path to info array
                        this.$wire.addFileInfo(file.name, uploadedFilename);
                        this.localFiles[index].uploaded = true;
                        this.successCount++;
                        resolve("Upload successful");
                    },
                    (error) => {
                        this.localFiles[index].error = true;
                        this.localFiles[index].errorMessage = error;
                        this.failureCount++;
                        reject("Upload failed");
                    },
                );
            });
        }
    }));
</script>
@endscript
