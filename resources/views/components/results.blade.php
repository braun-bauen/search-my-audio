<?php

use App\Models\Search;
use App\Enums\SearchStatus;
use Livewire\Volt\Component;
use Livewire\Attributes\{Computed};
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

new class extends Component {
    use \Livewire\WithPagination;

    public int $id;
    public Search $search;
    public string $query;
    public string $activeTab = 'all';
    public string $sortBy = 'query_count'; // Default sort column
    public string $sortDirection = 'desc'; // Default sort direction
    public string $sortString = 'query_count|desc';

    // TODO Cancel job? delete should cancel as well

    public function mount($id)
    {
        $this->id = $id;
        $this->search = Search::findOrFail($id);

        $this->authorize('view', $this->search);

        $this->query = $this->search->query;
    }

    public function rendering(View $view): void
    {
        $view->title('Results for "' . $this->query . '"');
    }

    public function updatedSortString(string $sortString): void
    {
        $this->resetPage();
        [$this->sortBy, $this->sortDirection] = explode('|', $sortString);
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function filteredFiles()
    {
        // Create where clause based on active tab
        $whereClause = [];
        if ($this->activeTab === 'matches') {
            $whereClause[] = ['query_count', '>', 0];
        } elseif ($this->activeTab === 'misses') {
            $whereClause[] = ['query_count', '=', 0];
        }

        return $this->search->files()
            ->where($whereClause)
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);
    }

    #[Computed]
    public function status(): array
    {
        if ($this->search->status === SearchStatus::Completed) {
            return [
                'color' => 'green',
                'icon' => 'check-circle',
            ];
        } elseif ($this->search->status === SearchStatus::Processing) {
            return [
                'color' => 'cyan',
                'icon' => '',
            ];
        } elseif ($this->search->status === SearchStatus::Pending) {
            return [
                'color' => 'yellow',
                'icon' => 'arrow-up-circle',
            ];
        } else {
            return [
                'color' => 'red',
                'icon' => 'exclamation-triangle',
            ];
        }
    }

    /**
     * Deletes the current search and redirects to the new search page.
     *
     * Logs the deletion attempt and any errors encountered during the process.
     */
    public function delete()
    {
        try {
            Log::info('Search: deleting search {search}', ['search' => $this->search->id]);
            $this->redirectRoute('new', navigate: true);
            $this->search->delete();
        } catch (\Exception $e) {
            Log::error('Search: error deleting search {search}: {exception}', ['search' => $this->search, 'exception' => $e]);
        }
    }

    public function downloadReport(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $name = 'audio-search-report-' . $this->search->id . '.csv';

        return Storage::download($this->search->report_path, $name);
    }
}; ?>

<div>
    <div class="flex flex-row flex-wrap items-center justify-between gap-2 mb-12">
        <flux:heading size="xl" level="1">Results for "{{ $query }}"</flux:heading>

        <flux:modal.trigger name="delete-search">
            <flux:button size="sm" icon="trash">Delete</flux:button>
        </flux:modal.trigger>
    </div>

    <!-- Mount confirm delete modal -->
    <livewire:confirm-delete @delete="delete" name="delete-search" />

    <!-- Processing status/results -->
    <div @if ($search->status !== SearchStatus::Completed) wire:poll.2s @endif>
        <flux:callout class="mb-4" inline color="{{ $this->status['color'] }}" icon="{{ $this->status['icon'] }}">
            @if ($search->status === SearchStatus::Processing)
            <flux:callout.heading class="justify-between">
                Processing {{ count($search->files) }} {{ Str::plural('file', count($search->files)) }}
                <flux:icon.loading variant="micro" />
            </flux:callout.heading>
            @elseif ($search->status === SearchStatus::Completed && $search->query_total > 0)
            <flux:callout.heading>
                Processing Completed
                <flux:text>{{ $search->query_total }} total {{ Str::plural('match', $search->query_total) }}</flux:text>
            </flux:callout.heading>

            @if (Auth::user()->subscribed() && $search->report_path !== null)
            <x-slot name="actions">
                <flux:button wire:click="downloadReport" icon="arrow-down-tray">Export Matches</flux:button>
            </x-slot>
            @endif
            @elseif ($search->status === SearchStatus::Completed && !$search->query_total > 0)
            <flux:callout.heading>
                Processing Completed
                <flux:text>No matches found</flux:text>
            </flux:callout.heading>
            @elseif ($search->status === SearchStatus::Pending)
            <flux:callout.heading class="justify-between">
                Uploading Files
                <flux:icon.loading variant="micro" />
            </flux:callout.heading>
            @else
            <flux:callout.heading>Processing failed</flux:callout.heading>
            @endif
        </flux:callout>
    </div>

    @if ($search->status !== SearchStatus::Pending)
    <!-- File controls -->
    <div class="flex flex-row flex-wrap gap-2 justify-between items-end">
        <div>
            <flux:heading size="lg" level="2">Files</flux:heading>
            <flux:text>
                {{ $search->status === SearchStatus::Completed ? 'Searched' : 'Searching' }} {{ count($search->files) }} {{ Str::plural('file', count($search->files)) }}
            </flux:text>
        </div>

        <div class="flex flex-row flex-wrap gap-2">
            <flux:tabs variant="segmented" wire:model.live="activeTab">
                <flux:tab name="all">All</flux:tab>
                <flux:tab name="matches">Matches</flux:tab>
                <flux:tab name="misses">Misses</flux:tab>
            </flux:tabs>

            <flux:select variant="listbox" class="max-w-fit" wire:model.live="sortString">
                <x-slot name="trigger">
                    <flux:select.button>
                        <flux:icon.arrows-up-down variant="micro" class="mr-2 text-zinc-400" />
                        <flux:select.selected />
                    </flux:select.button>
                </x-slot>

                <flux:select.option value="query_count|desc" selected>Matches</flux:select.option>
                <flux:select.option value="parsed_date|asc">Date - Ascending</flux:select.option>
                <flux:select.option value="parsed_date|desc">Date - Descending</flux:select.option>
                <flux:select.option value="created_at|asc">Upload Order</flux:select.option>
            </flux:select>
        </div>
    </div>
    <!-- File list -->
    <div wire:loading.delay.shortest wire:target="activeTab, sort" class="mt-4">
        <flux:icon.loading />
    </div>

    <div wire:loading.remove.delay.shortest wire:target="activeTab, sort" class="mt-4 flex flex-col gap-2">
        @if ($this->filteredFiles->isNotEmpty())
        @foreach ($this->filteredFiles as $file)
        <livewire:file-results :file="$file" wire:key="{{ $file->id }}" />
        @endforeach

        <flux:pagination :paginator="$this->filteredFiles" />
        @else
        <flux:subheading>No results found</flux:subheading>
        @endif
    </div>
    @else
    <flux:heading>This may take a few minutes</flux:heading>
    <flux:text>Feel free to close the page and return later.
        @if ($this->search->completion_email)
        <span>You will recieve an email when processing is completed.
        @endif
    </flux:text>
    @endif
</div>

@script
<script>
    $wire.on('copy-to-clipboard', (event) => {
        if (event.transcription) {
            navigator.clipboard.writeText(event.transcription);
            Flux.toast({
                heading: 'Copied!',
                text: 'The transcription has been copied to your clipboard.',
                variant: 'success',
            })
        } else {
            Flux.toast({
                heading: 'Uh oh!',
                text: 'The transcription could not be copied to your clipboard.',
                variant: 'failure',
            })
        }
    });
</script>
@endscript
