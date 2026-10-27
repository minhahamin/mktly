@php
    $fields = \App\Services\Segments\SegmentRuleEngine::FIELDS;
@endphp

<div>
    <x-page-header :title="$segmentId ? '세그먼트 편집' : '세그먼트 만들기'" subtitle="조건을 조합하면 오른쪽에서 대상 고객 수를 바로 확인할 수 있습니다.">
        <x-glass.button href="/segments" tint="plain">목록으로</x-glass.button>
    </x-page-header>

    <div class="grid items-start gap-6 xl:grid-cols-5">
        <x-glass.card strong class="xl:col-span-3">
            <form wire:submit="save" class="space-y-6" novalidate>
                <x-glass.input label="세그먼트 이름" wire:model="name" :error="$errors->first('name')" placeholder="예: VIP 고객" maxlength="100" />

                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-ink-700">조건 결합 방식</legend>
                    <div class="glass inline-flex rounded-full p-1" role="radiogroup">
                        @foreach (['all' => '모두 만족 (AND)', 'any' => '하나라도 만족 (OR)'] as $value => $text)
                            <label class="cursor-pointer">
                                <input type="radio" wire:model.live="match" value="{{ $value }}" class="peer sr-only">
                                <span class="flex h-9 items-center rounded-full px-4 text-sm font-semibold text-ink-500 transition peer-checked:glass-strong peer-checked:tint-pink peer-checked:text-ink-900 peer-focus-visible:outline-2 peer-focus-visible:outline-pink-600">{{ $text }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="space-y-3">
                    <h2 class="text-sm font-medium text-ink-700">조건</h2>

                    @foreach ($conditions as $i => $condition)
                        @php($def = $fields[$condition['field']] ?? null)
                        <div wire:key="condition-{{ $i }}" class="glass rounded-3xl p-4">
                            <div class="grid gap-3 md:grid-cols-[1fr_1fr_1fr_auto] md:items-start">
                                <x-glass.select aria-label="항목" wire:model.live="conditions.{{ $i }}.field">
                                    @foreach ($fields as $key => $f)
                                        <option value="{{ $key }}">{{ $f['label'] }}</option>
                                    @endforeach
                                </x-glass.select>

                                <x-glass.select aria-label="연산자" wire:model.live="conditions.{{ $i }}.op" :error="$errors->first('conditions.'.$i.'.op')">
                                    @foreach ($def['ops'] ?? [] as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </x-glass.select>

                                @if (($condition['op'] ?? '') === 'never')
                                    <div class="flex h-11 items-center px-2 text-sm text-ink-500">값 없음</div>
                                @else
                                    <x-glass.input
                                        aria-label="값"
                                        :type="($def['input'] ?? 'text') === 'text' ? 'text' : 'number'"
                                        :step="($def['input'] ?? '') === 'decimal' ? '0.01' : '1'"
                                        min="0"
                                        :placeholder="match ($def['input'] ?? '') { 'decimal' => '0 ~ 1', 'text' => '태그 이름', default => '숫자' }"
                                        wire:model.live.debounce.400ms="conditions.{{ $i }}.value"
                                        :error="$errors->first('conditions.'.$i.'.value')"
                                    />
                                @endif

                                <button type="button" wire:click="removeCondition({{ $i }})" class="focus-ring grid size-11 place-items-center rounded-full text-ink-500 hover:bg-white/70 hover:text-danger" aria-label="조건 삭제">
                                    <x-icon name="x" :size="18" />
                                </button>
                            </div>
                        </div>
                    @endforeach

                    @error('conditions')<p class="px-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror

                    <x-glass.button type="button" tint="lavender" size="sm" icon="plus" wire:click="addCondition" :disabled="count($conditions) >= 10">조건 추가</x-glass.button>
                </div>

                <div class="flex justify-end gap-3 border-t border-line pt-5">
                    <x-glass.button href="/segments" tint="plain">취소</x-glass.button>
                    <x-glass.button type="submit" wire:loading.attr="disabled" wire:target="save">저장</x-glass.button>
                </div>
            </form>
        </x-glass.card>

        <div class="space-y-5 xl:sticky xl:top-6 xl:col-span-2">
            <x-glass.card>
                <p class="text-sm font-medium text-ink-500">대상 고객</p>
                <p class="mt-2 text-4xl font-bold tracking-tight">
                    {{ number_format($this->previewCount) }}<span class="ml-1 text-lg font-semibold text-ink-500">명</span>
                </p>
                <p class="mt-1 text-sm text-ink-500">
                    전체 {{ number_format($this->totalContacts) }}명 중
                    {{ $this->totalContacts ? number_format($this->previewCount / $this->totalContacts * 100, 1) : 0 }}%
                </p>

                @if (count($this->previewConditions) < count($conditions))
                    <p class="mt-3 rounded-2xl bg-peach-100/70 px-3 py-2 text-xs text-ink-700">값이 비어 있는 조건은 미리보기에서 제외됩니다.</p>
                @endif
            </x-glass.card>

            <x-glass.card strong>
                <h2 class="mb-3 font-bold">해당 고객 예시</h2>
                @forelse ($this->sampleContacts as $contact)
                    <div wire:key="sample-{{ $contact->id }}" class="flex items-center justify-between gap-3 border-b border-line/70 py-2.5 text-sm last:border-0">
                        <span class="font-semibold">{{ $contact->name }}</span>
                        <span class="truncate text-ink-500">{{ $contact->email }}</span>
                    </div>
                @empty
                    <p class="py-4 text-sm text-ink-500">조건에 맞는 고객이 없습니다.</p>
                @endforelse
            </x-glass.card>
        </div>
    </div>
</div>
