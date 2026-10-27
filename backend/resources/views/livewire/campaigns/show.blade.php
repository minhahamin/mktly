@php
    $c = $campaign;
    $s = $this->stats;
    $pct = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100, 1) : 0;
    $funnel = [
        ['발송', $s['sent'], 'bg-lavender-400'],
        ['오픈', $s['opened'], 'bg-pink-400'],
        ['클릭', $s['clicked'], 'bg-peach-400'],
        ['전환', $s['conversions'], 'bg-mint-400'],
    ];
@endphp

<div @if ($c->status === 'sending') wire:poll.3s="refreshCampaign" @endif>
    @if (session('notice'))
        <div x-data x-init="$nextTick(() => $dispatch('notify', { message: @js(session('notice')) }))"></div>
    @endif

    <x-page-header :title="$c->name" :subtitle="$c->subject">
        <x-glass.badge :status="$c->status" class="!text-sm" />
        @can('update', $c)
            @if ($c->status === 'scheduled')
                <x-glass.button type="button" tint="plain" wire:click="cancelSchedule">예약 취소</x-glass.button>
            @endif
            <x-glass.button :href="'/campaigns/'.$c->id.'/edit'" tint="lavender" icon="edit">수정</x-glass.button>
        @endcan
        <x-glass.button href="/campaigns" tint="plain">목록으로</x-glass.button>
    </x-page-header>

    @if ($c->status === 'scheduled' && $c->scheduled_at)
        <div class="glass tint-lavender mb-6 flex items-center gap-3 rounded-3xl px-5 py-4 text-sm" role="status">
            <x-icon name="clock" :size="18" />
            <span><strong>{{ $c->scheduled_at->format('Y.m.d H:i') }}</strong>에 발송될 예정입니다.</span>
        </div>
    @elseif ($c->status === 'sending')
        <div class="glass tint-peach mb-6 rounded-3xl px-5 py-4 text-sm" role="status" aria-live="polite">
            <div class="flex items-center justify-between gap-3">
                <span class="font-semibold">발송 중입니다. 큐 워커가 메일을 처리하고 있어요.</span>
                <span>{{ number_format($s['sent'] + $s['failed']) }} / {{ number_format($s['total']) }}</span>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/60">
                <div class="h-full rounded-full bg-peach-400 transition-all" style="width: {{ $pct($s['sent'] + $s['failed'], $s['total']) }}%"></div>
            </div>
        </div>
    @elseif ($c->status === 'failed')
        <div class="glass mb-6 flex items-center gap-3 rounded-3xl border-danger/40 px-5 py-4 text-sm text-danger" role="alert">
            <x-icon name="alert" :size="18" /> 발송에 실패했습니다. 아래 수신자 목록에서 오류를 확인하세요.
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="발송" icon="send" tint="lavender" :value="number_format($s['sent'])" />
        <x-stat-card label="오픈율" icon="eye" tint="pink" :value="$pct($s['opened'], $s['sent']).'%'" />
        <x-stat-card label="클릭률" icon="pointer" tint="peach" :value="$pct($s['clicked'], $s['sent']).'%'" />
        <x-stat-card label="실패" icon="alert" tint="mint" :value="number_format($s['failed'])" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-5">
        <x-glass.card strong class="xl:col-span-3">
            <h2 class="text-lg font-bold">전환 퍼널</h2>
            <p class="mb-5 text-sm text-ink-500">발송에서 전환까지 각 단계에 도달한 고객 수</p>

            <div class="space-y-4">
                @foreach ($funnel as [$label, $count, $color])
                    <div wire:key="funnel-{{ $label }}">
                        <div class="mb-1.5 flex justify-between text-sm">
                            <span class="font-semibold">{{ $label }}</span>
                            <span class="text-ink-700">{{ number_format($count) }}명 <span class="text-ink-500">({{ $pct($count, $s['sent']) }}%)</span></span>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-white/70">
                            <div class="h-full rounded-full {{ $color }}" style="width: {{ $pct($count, $s['sent']) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-glass.card>

        <x-glass.card strong class="xl:col-span-2">
            <h2 class="mb-4 text-lg font-bold">링크별 클릭</h2>
            @forelse ($this->links as $link)
                <div wire:key="link-{{ $link->id }}" class="flex items-center justify-between gap-3 border-b border-line/70 py-2.5 text-sm last:border-0">
                    <span class="truncate text-ink-700" title="{{ $link->target_url }}">{{ $link->target_url }}</span>
                    <span class="shrink-0 font-semibold">{{ number_format($link->click_count) }}회</span>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-ink-500">본문에 추적 링크가 없습니다.</p>
            @endforelse
        </x-glass.card>
    </div>

    <x-glass.card strong class="mt-6">
        <h2 class="mb-4 text-lg font-bold">수신자</h2>
        @php($recipients = $this->recipients)

        <div class="overflow-x-auto">
            <table class="w-full min-w-[34rem] text-left text-sm">
                <thead class="text-ink-500">
                    <tr>
                        <th class="pb-3 font-medium">고객</th>
                        <th class="pb-3 font-medium">이메일</th>
                        <th class="pb-3 font-medium">상태</th>
                        <th class="pb-3 font-medium">오픈</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($recipients as $message)
                        <tr wire:key="msg-{{ $message->id }}">
                            <td class="py-3 font-semibold">{{ $message->contact?->name }}</td>
                            <td class="py-3 text-ink-700">{{ $message->contact?->email }}</td>
                            <td class="py-3">
                                @if ($message->status === 'failed')
                                    <span class="text-danger">실패</span>
                                    @if ($message->error)<span class="block max-w-xs truncate text-xs text-ink-500" title="{{ $message->error }}">{{ $message->error }}</span>@endif
                                @elseif ($message->status === 'pending')
                                    <span class="text-ink-500">대기 중</span>
                                @else
                                    <span>발송됨</span>
                                @endif
                            </td>
                            <td class="py-3 text-ink-500">{{ $message->opened_at?->format('m.d H:i') ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-10 text-center text-ink-500">아직 발송 기록이 없습니다.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $recipients->links('pagination.glass') }}
    </x-glass.card>
</div>
