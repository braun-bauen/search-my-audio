<?php

use App\Models\Search;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('History')] class extends Component
{
    use \Livewire\WithPagination;

    public string $sortBy = 'created_at';

    public string $sortDirection = 'desc';

    public Search $selectedSearch;

    public function setSelected(Search $search): void
    {
        $this->selectedSearch = $search;
    }

    public function delete(): void
    {
        try {
            Log::info('History: deleting search {search}', ['search' => $this->selectedSearch->id]);
            $this->selectedSearch->delete();
            // Close modal
            Flux::modal('delete-search')->close();
            // Refresh computed searches() property
            unset($this->searches);
        } catch (\Exception $e) {
            Log::error('History: error deleting search {search}: {exception}', ['search' => $this->selectedSearch->id, 'exception' => $e]);
        }
    }

    public function sort($column)
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }

    #[Computed]
    public function searches()
    {
        return Auth::user()->searches()
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);
    }
}; ?>

<div>
    <flux:heading size="xl" level="1">History</flux:heading>
    <flux:subheading>View and delete past searches.</flux:subheading>

    <livewire:confirm-delete @delete="delete" name="delete-search" />

    <flux:table :paginate="$this->searches" class="mt-12">
        <flux:table.columns>
            <flux:table.column sortable :sorted="$sortBy === 'query'" :direction="$sortDirection" wire:click="sort('query')">Query</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'query_total'" :direction="$sortDirection" wire:click="sort('query_total')">Matches</flux:table.column>
            <flux:table.column sortable :sorted="$sortBy === 'created_at'" :direction="$sortDirection" wire:click="sort('created_at')">Date</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @if ($this->searches->isNotEmpty())
            @foreach ($this->searches as $search)
            <flux:table.row wire:key="{{ $search->id }}">
                <flux:table.cell>
                    <flux:button
                        href="results/{{ $search->id }}"
                        wire:navigate.hover
                        variant="ghost"
                        icon-trailing="arrow-top-right-on-square"
                        label="View the results for '{{ $search->query }}'">
                        {{ $search->query }}
                    </flux:button>
                </flux:table.cell>

                @if ($search->status === \App\Enums\SearchStatus::Completed)
                <flux:table.cell>{{ $search->query_total ? $search->query_total : '0' }}</flux:table.cell>
                @else
                <flux:table.cell>Processing</flux:table.cell>
                @endif

                <flux:table.cell>{{ $search->formatted_created_at }}</flux:table.cell>

                <flux:table.cell>
                    <flux:dropdown position="bottom" align="start">
                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom"></flux:button>

                        <flux:menu>
                            <flux:modal.trigger name="delete-search" wire:click="setSelected({{ $search }})">
                                <flux:menu.item icon="trash" variant="danger">Delete</flux:menu.item>
                            </flux:modal.trigger>
                        </flux:menu>
                    </flux:dropdown>
                </flux:table.cell>
            </flux:table.row>
            @endforeach
            @else
            <flux:table.row>
                <flux:table.cell colspan="4">
                    No past searches found
                </flux:table.cell>
            </flux:table.row>
            @endif
        </flux:table.rows>
    </flux:table>
</div>
