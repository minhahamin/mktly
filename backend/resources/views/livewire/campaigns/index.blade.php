<div>
    @if (session('notice'))
        <div x-data x-init="$nextTick(() => $dispatch('notify', { message: @js(session('notice')) }))"></div>
    @endif

    <x-page-header title="캠페인" subtitle="이메일 캠페인을 만들고 발송 성과를 확인합니다.">
        @if ($this->canManage)
            <x-glass.button href="/campaigns/create" icon="plus">새 캠페인</x-glass.button>
        @endif
    </x-page-header>

    <x-glass.card strong padding="p-5 sm:p-6">
        <div class="mb-5 grid gap-3 sm:grid-cols-[1fr_14rem]">
            <x-glass.input icon="search" type="search" placeholder="캠페인 이름 검색" aria-label="캠페인 검색" wire:model.live.debounce.300ms="search" />
            <x-glass.select aria-label="상태 필터" wire:model.live="status">
                <option value="">모든 상태</option>
                @foreach (['draft' => '임시저장', 'scheduled' => '예약됨', 'sending' => '발송 중', 'sent' => '발송 완료', 'failed' => '실패'] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-glass.select>
        </div>

        @php($campaigns = $this->campaigns)

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,status,gotoPage,nextPage,previousPage">
            <table class="w-full min-w-[46rem] text-left text-sm">
                <thead class="text-ink-500">
                    <tr>
                        <th class="pb-3 font-medium">캠페인</th>
                        <th class="pb-3 font-medium">상태</th>
                        <th class="pb-3 font-medium">대상</th>
                        <th class="pb-3 text-right font-medium">발송</th>
                        <th class="pb-3 text-right font-medium">오픈율</th>
                        <th class="pb-3 text-right font-medium">클릭률</th>
                        <th class="pb-3"><span class="sr-only">작업</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($campaigns as $campaign)
                        <tr wire:key="campaign-{{ $campaign->id }}">
                            <td class="py-3">
                                <a href="/campaigns/{{ $campaign->id }}" class="focus-ring rounded font-semibold hover:text-pink-700">{{ $campaign->name }}</a>
                                <p class="max-w-xs truncate text-xs text-ink-500">{{ $campaign->subject }}</p>
                            </td>
                            <td class="py-3">
                                <x-glass.badge :status="$campaign->status" />
                                @if ($campaign->status === 'scheduled' && $campaign->scheduled_at)
                                    <p class="mt-1 text-xs text-ink-500">{{ $campaign->scheduled_at->format('m.d H:i') }}</p>
                                @endif
                            </td>
                            <td class="py-3 text-ink-700">{{ $campaign->segment?->name ?? '전체 고객' }}</td>
                            <td class="py-3 text-right">{{ $campaign->sent_count ? number_format($campaign->sent_count) : '-' }}</td>
                            <td class="py-3 text-right">{{ $campaign->sent_count ? round($campaign->opened_count / $campaign->sent_count * 100, 1).'%' : '-' }}</td>
                            <td class="py-3 text-right">{{ $campaign->sent_count ? round($campaign->click_count / $campaign->sent_count * 100, 1).'%' : '-' }}</td>
                            <td class="py-3">
                                <div class="flex justify-end gap-1">
                                    <a href="/campaigns/{{ $campaign->id }}" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70" aria-label="{{ $campaign->name }} 상세"><x-icon name="eye" :size="16" /></a>
                                    @can('update', $campaign)
                                        <a href="/campaigns/{{ $campaign->id }}/edit" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70" aria-label="{{ $campaign->name }} 수정"><x-icon name="edit" :size="16" /></a>
                                    @endcan
                                    @can('delete', $campaign)
                                        <button type="button" wire:click="confirmDelete({{ $campaign->id }})" class="focus-ring grid size-9 place-items-center rounded-full text-ink-500 hover:bg-white/70 hover:text-danger" aria-label="{{ $campaign->name }} 삭제"><x-icon name="trash" :size="16" /></button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-14 text-center text-ink-500">
                                {{ $status !== '' || $search !== '' ? '조건에 맞는 캠페인이 없습니다.' : '아직 캠페인이 없습니다. 첫 캠페인을 만들어 보세요.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $campaigns->links('pagination.glass') }}
    </x-glass.card>

    @if ($deletingId)
        <x-glass.modal title="캠페인을 삭제할까요?" close="cancelDelete">
            <p class="text-sm text-ink-700">발송 기록과 오픈/클릭 이벤트도 함께 삭제되며 복구할 수 없습니다.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-glass.button type="button" tint="plain" wire:click="cancelDelete">취소</x-glass.button>
                <x-glass.button type="button" tint="peach" icon="trash" wire:click="delete">삭제</x-glass.button>
            </div>
        </x-glass.modal>
    @endif
</div>
