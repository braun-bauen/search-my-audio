<?php

use Livewire\Volt\Component;

new class extends Component {
    public string $name;

    public function delete()
    {
        $this->dispatch('delete');
    }
}; ?>

<div>
    <flux:modal :name="$name" class="min-w-[22rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete search?</flux:heading>

                <flux:subheading>
                    <p>You're about to delete this search.</p>
                    <p>This action cannot be reversed.</p>
                </flux:subheading>
            </div>

            <div class="flex gap-2">
                <flux:spacer />

                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>

                <flux:button wire:click="delete" type="submit" variant="danger">Delete search</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
