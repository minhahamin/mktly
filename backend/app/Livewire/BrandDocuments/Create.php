<?php

namespace App\Livewire\BrandDocuments;

use App\Models\BrandDocument;
use App\Services\Brand\BrandDocuments;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts::app')]
#[Title('브랜드 문서 등록')]
class Create extends Component
{
    use WithFileUploads;

    public string $title = '';

    public string $content = '';

    public $file = null;

    public function mount(): void
    {
        $this->authorize('create', BrandDocument::class);
    }

    public function save(BrandDocuments $documents)
    {
        $this->authorize('create', BrandDocument::class);

        $this->validate([
            'title' => ['required', 'string', 'max:100'],
            'content' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'extensions:txt,md', 'max:50'],
        ], [
            'title.required' => '문서 제목을 입력해 주세요.',
            'title.max' => '제목은 100자 이하로 입력해 주세요.',
            'file.extensions' => '.txt 또는 .md 파일만 올릴 수 있습니다.',
            'file.max' => '파일 크기는 50KB 이하여야 합니다.',
        ]);

        // 파일을 올리면 파일 내용이 우선한다.
        $text = $this->file ? (string) file_get_contents($this->file->getRealPath()) : $this->content;

        if (strlen($text) > BrandDocuments::MAX_BYTES) {
            $this->addError($this->file ? 'file' : 'content', '내용은 50KB 이하여야 합니다.');

            return null;
        }

        try {
            $documents->create(trim($this->title), $text, auth()->id());
        } catch (DomainException $e) {
            $this->addError($this->file ? 'file' : 'content', $e->getMessage());

            return null;
        }

        session()->flash('notice', '브랜드 문서를 등록했습니다. 검색 준비가 끝나면 AI 카피에 반영됩니다.');

        return $this->redirect('/brand-documents', navigate: true);
    }

    public function render()
    {
        return view('livewire.brand-documents.create');
    }
}
