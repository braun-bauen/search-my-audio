<?php

use Livewire\Attributes\Modelable;
use Livewire\Volt\Component;

new class extends Component
{
    #[Modelable]
    public string $timezone = '';
}; ?>

<div>
    <flux:select
        :label="__('Timezone')"
        searchable
        placeholder="Choose a timezone"
        variant="listbox"
        wire:model="timezone">
        @foreach (timezone_identifiers_list() as $timezone)
        <flux:select.option
            value="{{ $timezone }}"
            :selected="$this->timezone === $timezone">
            {{ Str::replace('_', ' ', $timezone) }}
        </flux:select.option>
        @endforeach
    </flux:select>
</div>
