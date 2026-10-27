<?php

namespace App\Livewire\Segments;

use App\Models\Contact;
use App\Models\Segment;
use App\Services\Segments\SegmentRuleEngine;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('세그먼트 편집')]
class Form extends Component
{
    public ?int $segmentId = null;

    public string $name = '';

    public string $match = SegmentRuleEngine::MATCH_ALL;

    /** @var list<array{field: string, op: string, value: mixed}> */
    public array $conditions = [];

    public function mount(?Segment $segment = null): void
    {
        if ($segment?->exists) {
            $this->authorize('update', $segment);

            $rules = app(SegmentRuleEngine::class)->normalize($segment->rules);
            $this->segmentId = $segment->id;
            $this->name = $segment->name;
            $this->match = $rules['match'];
            $this->conditions = $rules['conditions'];

            return;
        }

        $this->authorize('create', Segment::class);
        $this->addCondition();
    }

    public function addCondition(): void
    {
        if (count($this->conditions) >= 10) {
            return;
        }

        $this->conditions[] = $this->blankCondition('total_spent');
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
    }

    /** 항목(field)이 바뀌면 연산자와 값을 해당 항목의 기본값으로 되돌린다. */
    public function updatedConditions(mixed $value, string $key): void
    {
        [$index, $property] = array_pad(explode('.', $key, 2), 2, null);

        if ($property === 'field' && isset(SegmentRuleEngine::FIELDS[$value])) {
            $this->conditions[(int) $index] = $this->blankCondition($value);
        }

        if ($property === 'op' && $value === 'never') {
            $this->conditions[(int) $index]['value'] = null;
        }
    }

    public function save()
    {
        $this->authorize($this->segmentId ? 'update' : 'create', $this->segmentId ? Segment::findOrFail($this->segmentId) : Segment::class);

        // 이름과 조건 오류를 한 번에 보여주기 위해 이름 검증 예외를 잡고 조건 검증을 이어서 한다.
        $nameValid = true;

        try {
            $this->validate(
                ['name' => ['required', 'string', 'max:100'], 'match' => ['in:all,any']],
                ['name.required' => '세그먼트 이름을 입력해 주세요.', 'name.max' => '이름은 100자 이하로 입력해 주세요.'],
            );
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());
            $nameValid = false;
        }

        if (! $this->validateConditions() || ! $nameValid) {
            return;
        }

        $payload = [
            'name' => trim($this->name),
            'rules' => app(SegmentRuleEngine::class)->normalize(['match' => $this->match, 'conditions' => $this->conditions]),
        ];

        $this->segmentId ? Segment::findOrFail($this->segmentId)->update($payload) : Segment::create($payload);

        session()->flash('notice', $this->segmentId ? '세그먼트를 수정했습니다.' : '세그먼트를 만들었습니다.');

        return $this->redirect('/segments', navigate: true);
    }

    #[Computed]
    public function previewConditions(): array
    {
        return app(SegmentRuleEngine::class)->completeConditions($this->conditions);
    }

    #[Computed]
    public function previewCount(): int
    {
        return app(SegmentRuleEngine::class)->count(['match' => $this->match, 'conditions' => $this->previewConditions]);
    }

    #[Computed]
    public function totalContacts(): int
    {
        return Contact::query()->count();
    }

    #[Computed]
    public function sampleContacts()
    {
        $engine = app(SegmentRuleEngine::class);

        return $engine->apply(Contact::query(), ['match' => $this->match, 'conditions' => $this->previewConditions])
            ->orderBy('id')->limit(5)->get(['id', 'name', 'email']);
    }

    public function render()
    {
        return view('livewire.segments.form');
    }

    private function validateConditions(): bool
    {
        $engine = app(SegmentRuleEngine::class);
        $valid = true;

        if ($this->conditions === []) {
            $this->addError('conditions', '조건을 하나 이상 추가해 주세요.');

            return false;
        }

        foreach ($this->conditions as $i => $condition) {
            $field = $condition['field'] ?? '';
            $op = $condition['op'] ?? '';

            if (! isset(SegmentRuleEngine::FIELDS[$field]['ops'][$op])) {
                $this->addError("conditions.{$i}.op", '조건을 선택해 주세요.');
                $valid = false;

                continue;
            }

            if ($op !== 'never' && ! $engine->isValidValue($field, $condition['value'] ?? null)) {
                $this->addError("conditions.{$i}.value", match (SegmentRuleEngine::FIELDS[$field]['input']) {
                    'decimal' => '0과 1 사이의 값을 입력해 주세요.',
                    'text' => '태그 이름을 입력해 주세요.',
                    default => '0 이상의 정수를 입력해 주세요.',
                });
                $valid = false;
            }
        }

        return $valid;
    }

    /** @return array{field: string, op: string, value: mixed} */
    private function blankCondition(string $field): array
    {
        return ['field' => $field, 'op' => array_key_first(SegmentRuleEngine::FIELDS[$field]['ops']), 'value' => ''];
    }
}
