<?php

use App\Models\Search;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('New')] class extends Component {
    use WithFileUploads;
    // TODO: clearing files doesn't cancel submit button loading

    #[Validate('required')]
    public $query = '';

    #[Validate('required')]
    public $completionEmail = true;

    #[
        Validate(
            [
                'uploadedFiles' => 'required',
            ],
            attribute: [
                'uploadedFiles.*' => 'file',
            ],
            message: [
                'uploadedFiles.*' => 'Please upload audio files.',
            ],
        ),
    ]
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
}; 