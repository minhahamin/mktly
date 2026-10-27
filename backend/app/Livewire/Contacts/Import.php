<?php

namespace App\Livewire\Contacts;

use App\Models\Contact;
use App\Services\Contacts\ContactImporter;
use App\Services\Contacts\ImportResult;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts::app')]
#[Title('CSV 업로드')]
class Import extends Component
{
    use WithFileUploads;

    public $file = null;

    public ?string $failure = null;

    public ?array $summary = null;

    public function mount(): void
    {
        $this->authorize('import', Contact::class);
    }

    public function import(ContactImporter $importer): void
    {
        $this->authorize('import', Contact::class);
        $this->reset(['failure', 'summary']);

        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ], [
            'file.required' => 'CSV 파일을 선택해 주세요.',
            'file.mimes' => 'CSV 파일만 업로드할 수 있습니다.',
            'file.max' => '파일 크기는 2MB 이하여야 합니다.',
        ]);

        try {
            $result = $importer->import($this->file->getRealPath(), auth()->user()->currentWorkspaceId());
        } catch (InvalidArgumentException $e) {
            $this->failure = $e->getMessage();

            return;
        } finally {
            $this->reset('file');
        }

        $this->summary = $this->summarize($result);
    }

    public function render()
    {
        return view('livewire.contacts.import');
    }

    /** @return array<string, mixed> Livewire 상태로 직렬화 가능한 형태 */
    private function summarize(ImportResult $result): array
    {
        return [
            'total' => $result->total,
            'imported' => $result->imported,
            'duplicates' => $result->duplicates,
            'invalid' => $result->invalid,
            'errors' => $result->errors,
            'max_errors' => ImportResult::MAX_REPORTED_ERRORS,
        ];
    }
}
