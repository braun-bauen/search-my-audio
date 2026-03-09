<?php

use App\Jobs\ProcessFile;
use App\Models\AudioFile;
use App\Enums\FileStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component
{
    public AudioFile $file;

    /**
     * Retrieves the transcription segments for the audio file.
     *
     * @return array The transcription data as an array of segments.
     */
    #[Computed]
    public function transcription(): array
    {
        return $this->file->getTranscription();
    }

    #[Computed()]
    public function badgeString(): string
    {
        return $this->file->query_count.' '.Str::plural('Match', $this->file->query_count);
    }

    #[Computed()]
    public function copyTitle(): string
    {
        return 'Copy transcription of '.$this->file->audio_filename.' to clipboard';
    }

    /**
     * Resets the audio file's transcription state and re-queues it for processing.
     *
     * Sets the file status to "Uploaded", clears any existing transcription path, saves the changes, and dispatches a job to retry processing the file.
     */
    public function retry()
    {
        // Reset transcription path and failed var to show correct UI state
        $this->file->transcription_path = null;
        $this->file->status = FileStatus::Uploaded;
        $this->file->save();

        ProcessFile::dispatch($this->file->search, $this->file, true);
    }

    /**
     * Dispatches a browser event to copy the full transcription text to the clipboard.
     */
    public function copyTranscription(): void
    {
        $fullText = Arr::get($this->transcription, 'fullText');
        $this->dispatch('copy-to-clipboard', transcription: $fullText);
    }
}; ?>

<div @if ($this->file->status === FileStatus::Uploaded) wire:poll.2s @endif>
    <flux:card class="!p-4">
        <div class="flex flex-row flex-wrap gap-2 justify-between">
            <div>
                <flux:heading>
                    <span class="break-all mr-1">
                        @if ($file->parsed_date)
                        {{ $file->formatted_parsed_date }}
                        @else
                        {{ $file->audio_filename }}
                        @endif
                    </span>

                    @if ($this->file->query_count !== null)
                    <flux:badge size="sm" inset="top bottom" color="{{ $this->file->query_count === 0 ? 'rose' : 'emerald' }}">
                        {{ $this->badgeString }}
                    </flux:badge>
                    @endif
                </flux:heading>

                @if ($file->parsed_date)
                <flux:text class="mt-1 break-all">{{ $file->audio_filename }}</flux:text>
                @endif
            </div>

            @if ($this->file->status === FileStatus::Uploaded)
            <flux:icon.loading variant="micro" />
            @elseif ($this->file->status === FileStatus::Transcribed && Arr::has($this->transcription, "fullText"))
            <flux:button
                wire:click="copyTranscription"
                icon:trailing="document-duplicate"
                variant="subtle"
                size="sm"
                title="{{ $this->copyTitle }}"
                inset></flux:button>
            @else
            <flux:tooltip toggleable>
                <flux:button icon="exclamation-triangle" size="sm" inset variant="subtle" />

                <flux:tooltip.content>The transcription is missing.</flux:tooltip.content>
            </flux:tooltip>
            @endif
        </div>

        @if ($this->file->status === FileStatus::Transcribed)
        <flux:accordion class="mt-2" variant="reverse">
            <flux:accordion.item heading="View Transcription">
                <flux:accordion.content class="leading-loose">
                    @foreach ($this->transcription["segments"] as $segment)
                    @if ($segment["match"])
                    <flux:badge color="yellow" size="sm" inset="top bottom">{{ $segment["text"] }}</flux:badge>
                    @else
                    <span wire:key="$loop->index">{{ $segment["text"] }}</span>
                    @endif
                    @endforeach
                </flux:accordion.content>
            </flux:accordion.item>
        </flux:accordion>
        @elseif ($this->file->status === FileStatus::Failed)
        <flux:callout class="mt-2" icon="exclamation-triangle" color="red" inline>
            <flux:callout.heading>Processing failed</flux:callout.heading>

            <x-slot name="actions">
                <flux:button wire:click="retry">Retry</flux:button>
            </x-slot>
        </flux:callout>
        @endif
    </flux:card>
</div>
