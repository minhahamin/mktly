<div>
    <x-page-header title="AI 세그먼트 추천" subtitle="구매 최근성, 구매액, 메일 반응이 비슷한 고객끼리 자동으로 묶어 드립니다.">
        <x-glass.button href="/segments" tint="plain">목록으로</x-glass.button>
    </x-page-header>

    <x-glass.card strong>
        <div class="flex flex-wrap items-end gap-4">
            <div class="w-48">
                <x-glass.select label="나눌 그룹 수" wire:model="k">
                    @foreach (range(2, 8) as $n)
                        <option value="{{ $n }}">{{ $n }}개</option>
                    @endforeach
                </x-glass.select>
            </div>

            <x-glass.button type="button" icon="sparkles" wire:click="analyze" wire:loading.attr="disabled" wire:target="analyze">
                <span wire:loading.remove wire:target="analyze">고객 분석 실행</span>
                <span wire:loading wire:target="analyze">분석 중...</span>
            </x-glass.button>
        </div>

        <p class="mt-4 text-xs text-ink-500">
            분석하면 고객마다 그룹 번호가 저장됩니다. 다시 분석하면 번호가 바뀔 수 있으니, 이미 만든 "AI 클러스터" 세그먼트가 있다면 다시 확인해 주세요.
        </p>

        @if ($error)
            <p class="mt-4 rounded-2xl bg-danger/10 px-4 py-3 text-sm text-danger" role="alert">{{ $error }}</p>
        @endif
    </x-glass.card>

    @if ($clusters)
        <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($clusters as $cluster)
                <x-glass.card wire:key="cluster-{{ $cluster['id'] }}" class="flex flex-col" strong>
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-lg font-bold">{{ $cluster['name'] }}</h2>
                        <span class="glass tint-pink shrink-0 rounded-full px-3 py-1 text-xs font-semibold">{{ number_format($cluster['size']) }}명</span>
                    </div>
                    <p class="mt-2 flex-1 text-sm text-ink-500">{{ $cluster['description'] }}</p>

                    <dl class="mt-4 space-y-1.5 text-sm">
                        <div class="flex justify-between"><dt class="text-ink-500">평균 구매액</dt><dd class="font-semibold">{{ number_format($cluster['avg_spent']) }}원</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-500">마지막 구매</dt><dd class="font-semibold">{{ $cluster['avg_recency_days'] === null ? '이력 없음' : $cluster['avg_recency_days'].'일 전' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-500">메일 오픈율</dt><dd class="font-semibold">{{ round($cluster['open_rate'] * 100, 1) }}%</dd></div>
                    </dl>

                    @if (in_array($cluster['id'], $saved, true))
                        <p class="mt-5 flex items-center justify-center gap-1.5 rounded-full bg-mint-100 py-2 text-sm font-semibold"><x-icon name="check" :size="16" /> 세그먼트로 저장됨</p>
                    @else
                        <x-glass.button type="button" tint="lavender" size="sm" class="mt-5 w-full" wire:click="saveSegment({{ $cluster['id'] }})">세그먼트로 저장</x-glass.button>
                    @endif
                </x-glass.card>
            @endforeach
        </div>
    @endif
</div>
