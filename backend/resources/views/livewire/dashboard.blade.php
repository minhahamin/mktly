<div>
    <x-page-header title="대시보드" subtitle="캠페인 성과를 한눈에 확인하세요.">
        <div class="glass flex rounded-full p-1" role="group" aria-label="기간 선택">
            @foreach ([7 => '7일', 30 => '30일', 90 => '90일'] as $value => $text)
                <button type="button" wire:click="$set('days', {{ $value }})" @if ($days === $value) aria-pressed="true" @endif
                        class="focus-ring h-9 rounded-full px-4 text-sm font-semibold transition {{ $days === $value ? 'tint-pink glass-strong text-ink-900' : 'text-ink-500 hover:text-ink-900' }}">
                    {{ $text }}
                </button>
            @endforeach
        </div>
        <x-glass.button href="/campaigns" icon="plus">새 캠페인</x-glass.button>
    </x-page-header>

    <div wire:loading.delay class="mb-4 text-sm text-ink-500">불러오는 중...</div>

    @php($s = $this->summary)

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat-card label="발송 수" icon="send" tint="lavender" :value="number_format($s['sent']['value'])" :delta="$s['sent']['delta']" />
        <x-stat-card label="오픈율" icon="eye" tint="pink" :value="$s['open_rate']['value'].'%'" :delta="$s['open_rate']['delta']" />
        <x-stat-card label="클릭률" icon="pointer" tint="peach" :value="$s['click_rate']['value'].'%'" :delta="$s['click_rate']['delta']" />
        <x-stat-card label="전환 수" icon="target" tint="mint" :value="number_format($s['conversions']['value'])" :delta="$s['conversions']['delta']" />
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-5">
        <x-glass.card class="xl:col-span-3" strong>
            <h2 class="text-lg font-bold">일별 추이</h2>
            <p class="mb-4 text-sm text-ink-500">발송, 오픈, 클릭 이벤트 수</p>
            @php($t = $this->timeseries)
            <div wire:key="trend-{{ $days }}">
                <x-chart type="line" label="일별 발송, 오픈, 클릭 추이" :labels="$t['labels']" :datasets="[
                    ['label' => '발송', 'data' => $t['sent'], 'color' => 'lavender-400', 'fill' => true],
                    ['label' => '오픈', 'data' => $t['opens'], 'color' => 'pink-500'],
                    ['label' => '클릭', 'data' => $t['clicks'], 'color' => 'peach-400'],
                ]" />
            </div>
        </x-glass.card>

        <x-glass.card class="xl:col-span-2" strong>
            <h2 class="text-lg font-bold">캠페인별 성과</h2>
            <p class="mb-4 text-sm text-ink-500">오픈율과 클릭률 비교</p>
            @php($c = $this->campaigns)
            @if ($c->isEmpty())
                <div class="grid h-72 place-items-center text-sm text-ink-500">이 기간에 발송된 캠페인이 없습니다.</div>
            @else
                <div wire:key="bars-{{ $days }}">
                    <x-chart type="bar" suffix="%" label="캠페인별 오픈율과 클릭률" :labels="$c->pluck('name')->map(fn ($n) => \Illuminate\Support\Str::limit($n, 8))->all()" :datasets="[
                        ['label' => '오픈율', 'data' => $c->pluck('open_rate')->all(), 'color' => 'pink-500'],
                        ['label' => '클릭률', 'data' => $c->pluck('click_rate')->all(), 'color' => 'lavender-400'],
                    ]" />
                </div>
            @endif
        </x-glass.card>
    </div>

    <x-glass.card class="mt-6" strong>
        <h2 class="mb-4 text-lg font-bold">최근 발송 캠페인</h2>
        @if ($c->isEmpty())
            <p class="py-6 text-center text-sm text-ink-500">표시할 캠페인이 없습니다.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <thead class="text-ink-500">
                        <tr>
                            <th class="pb-3 font-medium">캠페인</th>
                            <th class="pb-3 font-medium">발송일</th>
                            <th class="pb-3 text-right font-medium">발송 수</th>
                            <th class="pb-3 text-right font-medium">오픈율</th>
                            <th class="pb-3 text-right font-medium">클릭률</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($c->reverse() as $row)
                            <tr wire:key="row-{{ $row['id'] }}">
                                <td class="py-3 font-semibold">{{ $row['name'] }}</td>
                                <td class="py-3 text-ink-500">{{ $row['sent_at']->format('Y.m.d') }}</td>
                                <td class="py-3 text-right">{{ number_format($row['sent']) }}</td>
                                <td class="py-3 text-right">{{ $row['open_rate'] }}%</td>
                                <td class="py-3 text-right">{{ $row['click_rate'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-glass.card>
</div>
