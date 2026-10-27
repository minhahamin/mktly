<div>
    <x-page-header title="고객" subtitle="마케팅 대상 고객을 검색하고 관리합니다.">
        @if ($this->canManage)
            <x-glass.button href="/contacts/import" tint="lavender" icon="upload">CSV 업로드</x-glass.button>
            <x-glass.button type="button" icon="plus" wire:click="create">고객 추가</x-glass.button>
        @endif
    </x-page-header>

    <x-glass.card strong padding="p-5 sm:p-6">
        <div class="mb-5 grid gap-3 sm:grid-cols-[1fr_14rem]">
            <x-glass.input icon="search" type="search" placeholder="이름 또는 이메일 검색" aria-label="고객 검색" wire:model.live.debounce.300ms="search" />
            <x-glass.select aria-label="태그 필터" wire:model.live="tag">
                <option value="">모든 태그</option>
                @foreach ($this->tagOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </x-glass.select>
        </div>

        @php($contacts = $this->contacts)

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,tag,sortBy,gotoPage,nextPage,previousPage">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="text-ink-500">
                    <tr>
                        @foreach (['name' => '이름', 'email' => '이메일', 'joined_at' => '가입일', 'last_purchase_at' => '마지막 구매', 'total_spent' => '누적 구매액'] as $field => $label)
                            <th class="pb-3 font-medium {{ $field === 'total_spent' ? 'text-right' : '' }}"
                                @if ($sort === $field) aria-sort="{{ $direction === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                <button type="button" wire:click="sortBy('{{ $field }}')" class="focus-ring inline-flex items-center gap-1 rounded-full px-1 hover:text-ink-900">
                                    {{ $label }}
                                    @if ($sort === $field)
                                        <x-icon name="chevron-down" :size="14" class="{{ $direction === 'asc' ? 'rotate-180' : '' }}" />
                                    @endif
                                </button>
                            </th>
                        @endforeach
                        <th class="pb-3 font-medium">태그</th>
                        @if ($this->canManage)<th class="pb-3"><span class="sr-only">작업</span></th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($contacts as $contact)
                        <tr wire:key="contact-{{ $contact->id }}">
                            <td class="py-3 font-semibold">{{ $contact->name }}</td>
                            <td class="py-3 text-ink-700">{{ $contact->email }}</td>
                            <td class="py-3 text-ink-500">{{ $contact->joined_at?->format('Y.m.d') ?? '-' }}</td>
                            <td class="py-3 text-ink-500">{{ $contact->last_purchase_at?->format('Y.m.d') ?? '-' }}</td>
                            <td class="py-3 text-right">{{ number_format($contact->total_spent) }}원</td>
                            <td class="py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($contact->tags ?? [] as $t)
                                        <span class="rounded-full border border-line bg-pink-50 px-2.5 py-0.5 text-xs text-ink-700">{{ $t }}</span>
                                    @endforeach
                                </div>
                            </td>
                            @if ($this->canManage)
                                <td class="py-3">
                                    <div class="flex justify-end gap-1">
                                        <button type="button" wire:click="edit({{ $contact->id }})" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70" aria-label="{{ $contact->name }} 수정"><x-icon name="edit" :size="16" /></button>
                                        <button type="button" wire:click="confirmDelete({{ $contact->id }})" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70 hover:text-danger" aria-label="{{ $contact->name }} 삭제"><x-icon name="trash" :size="16" /></button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-14 text-center text-ink-500">
                                @if ($search !== '' || $tag !== '')
                                    조건에 맞는 고객이 없습니다.
                                @else
                                    아직 고객이 없습니다. CSV로 한 번에 불러오거나 직접 추가해 보세요.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $contacts->links('pagination.glass') }}
    </x-glass.card>

    @if ($showForm)
        <x-glass.modal :title="$editingId ? '고객 수정' : '고객 추가'">
            <form wire:submit="save" class="space-y-4" novalidate>
                <x-glass.input label="이메일" type="email" wire:model="email" :error="$errors->first('email')" placeholder="customer@example.com" />
                <x-glass.input label="이름" wire:model="name" :error="$errors->first('name')" placeholder="홍길동" />
                <x-glass.input label="태그 (쉼표로 구분)" wire:model="tagsInput" :error="$errors->first('tagsInput')" placeholder="VIP, 뉴스레터" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-glass.input label="가입일" type="date" wire:model="joined_at" :error="$errors->first('joined_at')" />
                    <x-glass.input label="마지막 구매일" type="date" wire:model="last_purchase_at" :error="$errors->first('last_purchase_at')" />
                </div>
                <x-glass.input label="누적 구매액 (원)" type="number" min="0" wire:model="total_spent" :error="$errors->first('total_spent')" />

                <div class="flex justify-end gap-3 pt-2">
                    <x-glass.button type="button" tint="plain" wire:click="closeForm">취소</x-glass.button>
                    <x-glass.button type="submit" wire:loading.attr="disabled" wire:target="save">저장</x-glass.button>
                </div>
            </form>
        </x-glass.modal>
    @endif

    @if ($deletingId)
        <x-glass.modal title="고객을 삭제할까요?" close="cancelDelete">
            <p class="text-sm text-ink-700">삭제한 고객은 복구할 수 없고, 캠페인 발송 기록에서도 제거됩니다.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-glass.button type="button" tint="plain" wire:click="cancelDelete">취소</x-glass.button>
                <x-glass.button type="button" tint="peach" icon="trash" wire:click="delete">삭제</x-glass.button>
            </div>
        </x-glass.modal>
    @endif
</div>
