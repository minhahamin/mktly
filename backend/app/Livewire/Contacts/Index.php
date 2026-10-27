<?php

namespace App\Livewire\Contacts;

use App\Jobs\ScoreWorkspaceChurn;
use App\Models\Contact;
use App\Models\Workspace;
use App\Services\Contacts\ContactTagCatalog;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::app')]
#[Title('고객')]
class Index extends Component
{
    use WithPagination;

    private const SORTABLE = ['name', 'email', 'joined_at', 'last_purchase_at', 'total_spent', 'churn_score'];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $tag = '';

    #[Url]
    public string $sort = 'joined_at';

    #[Url]
    public string $direction = 'desc';

    public bool $showForm = false;

    public ?int $editingId = null;

    public ?int $deletingId = null;

    public string $email = '';

    public string $name = '';

    public string $tagsInput = '';

    public ?string $joined_at = null;

    public ?string $last_purchase_at = null;

    public int|string $total_spent = 0;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTag(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORTABLE, true)) {
            return;
        }

        $this->direction = $this->sort === $field && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $field;
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('create', Contact::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $contact = Contact::findOrFail($id);
        $this->authorize('update', $contact);

        $this->editingId = $contact->id;
        $this->email = $contact->email;
        $this->name = $contact->name;
        $this->tagsInput = implode(', ', $contact->tags ?? []);
        $this->joined_at = $contact->joined_at?->toDateString();
        $this->last_purchase_at = $contact->last_purchase_at?->toDateString();
        $this->total_spent = $contact->total_spent;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        $workspaceId = auth()->user()->currentWorkspaceId();

        if ($this->editingId) {
            $contact = Contact::findOrFail($this->editingId);
            $this->authorize('update', $contact);
        } else {
            $this->authorize('create', Contact::class);
        }

        $data = $this->validate([
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('contacts', 'email')->where('workspace_id', $workspaceId)->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'tagsInput' => ['nullable', 'string', 'max:255'],
            'joined_at' => ['nullable', 'date'],
            'last_purchase_at' => ['nullable', 'date'],
            'total_spent' => ['nullable', 'integer', 'min:0'],
        ], [
            'email.required' => '이메일을 입력해 주세요.',
            'email.email' => '올바른 이메일 형식이 아닙니다.',
            'email.unique' => '이미 등록된 이메일입니다.',
            'name.required' => '이름을 입력해 주세요.',
            'joined_at.date' => '날짜 형식이 올바르지 않습니다.',
            'last_purchase_at.date' => '날짜 형식이 올바르지 않습니다.',
            'total_spent.integer' => '숫자로 입력해 주세요.',
            'total_spent.min' => '0 이상이어야 합니다.',
        ]);

        $payload = [
            'email' => $data['email'],
            'name' => $data['name'],
            'tags' => $this->parseTags($data['tagsInput'] ?? ''),
            'joined_at' => $data['joined_at'] ?: null,
            'last_purchase_at' => $data['last_purchase_at'] ?: null,
            'total_spent' => (int) ($data['total_spent'] ?? 0),
        ];

        $isEdit = $this->editingId !== null;
        $isEdit ? $contact->update($payload) : Contact::create($payload);

        app(ContactTagCatalog::class)->forget($workspaceId);
        $this->closeForm();
        $this->dispatch('notify', message: $isEdit ? '고객 정보를 수정했습니다.' : '고객을 추가했습니다.');
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('delete', Contact::findOrFail($id));
        $this->deletingId = $id;
    }

    public function cancelDelete(): void
    {
        $this->deletingId = null;
    }

    public function delete(): void
    {
        $contact = Contact::findOrFail($this->deletingId);
        $this->authorize('delete', $contact);
        $contact->delete();

        app(ContactTagCatalog::class)->forget(auth()->user()->currentWorkspaceId());
        $this->deletingId = null;
        $this->dispatch('notify', message: '고객을 삭제했습니다.');
    }

    /** 이탈 점수 계산을 큐에 등록한다. AI 서비스 호출은 워커가 처리하므로 화면은 바로 돌아온다. */
    public function refreshChurn(): void
    {
        $this->authorize('create', Contact::class);

        ScoreWorkspaceChurn::dispatch(auth()->user()->currentWorkspaceId());
        $this->dispatch('notify', message: '이탈 점수 계산을 시작했습니다. 잠시 후 새로고침해 주세요.');
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    #[Computed]
    public function contacts()
    {
        $term = trim($this->search);
        $escaped = addcslashes($term, '%_\\');
        $sort = in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'joined_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        return Contact::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$escaped}%")
                ->orWhere('email', 'like', "%{$escaped}%")))
            ->when($this->tag !== '', fn ($q) => $q->whereJsonContains('tags', $this->tag))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(15);
    }

    #[Computed]
    public function tagOptions(): array
    {
        return app(ContactTagCatalog::class)->all(auth()->user()->currentWorkspaceId() ?? 0);
    }

    /** 마지막 이탈 점수 계산 시각과 모델 지표 */
    #[Computed]
    public function churnInfo(): ?array
    {
        $workspace = Workspace::find(auth()->user()->currentWorkspaceId());

        if ($workspace?->churn_scored_at === null) {
            return null;
        }

        return ['at' => $workspace->churn_scored_at, 'meta' => $workspace->churn_meta ?? []];
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->can('create', Contact::class);
    }

    public function render()
    {
        return view('livewire.contacts.index');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'email', 'name', 'tagsInput', 'joined_at', 'last_purchase_at']);
        $this->total_spent = 0;
        $this->resetValidation();
    }

    /** @return list<string>|null */
    private function parseTags(string $value): ?array
    {
        $tags = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,|;]/', $value) ?: []))));

        return $tags === [] ? null : $tags;
    }
}
