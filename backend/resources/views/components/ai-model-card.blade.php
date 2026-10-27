@php
    $workspace = \App\Models\Workspace::find(auth()->user()->currentWorkspaceId());
    $meta = $workspace?->churn_meta;
    $roc = $meta['roc'] ?? null;
@endphp

{{-- 사이드바의 AI 이탈 모델 성능 카드. 마지막 이탈 점수 계산 때 AI 서비스가 돌려준 교차 검증 지표를 보여준다. --}}
<a href="/contacts" class="glass-strong focus-ring mt-3 block rounded-2xl p-3 transition hover:brightness-105" aria-label="AI 이탈 모델 성능, 고객 화면으로 이동">
    <div class="flex items-center justify-between">
        <p class="flex items-center gap-1.5 text-xs font-semibold"><x-icon name="sparkles" :size="14" class="text-pink-600" /> AI 이탈 모델</p>
        @if ($roc)<span class="text-[10px] text-ink-500">교차 검증</span>@endif
    </div>

    @if ($roc)
        <x-chart type="line" :xy="true" :legend="false" height="h-24" class="mt-2" label="이탈 모델 ROC 곡선"
                 :datasets="[
                     ['label' => '무작위', 'data' => [['x' => 0, 'y' => 0], ['x' => 1, 'y' => 1]], 'color' => 'ink-500', 'dashed' => true],
                     ['label' => '모델', 'data' => collect($roc)->map(fn ($p) => ['x' => $p[0], 'y' => $p[1]])->all(), 'color' => 'pink-500', 'fill' => true],
                 ]" />

        <dl class="mt-2 grid grid-cols-3 gap-1 text-center">
            @foreach ([['AUC', $meta['auc'] ?? null], ['정밀도', $meta['precision'] ?? null], ['재현율', $meta['recall'] ?? null]] as [$label, $value])
                <div>
                    <dt class="text-[10px] text-ink-500">{{ $label }}</dt>
                    <dd class="text-sm font-bold">{{ $value === null ? '-' : number_format($value, 2) }}</dd>
                </div>
            @endforeach
        </dl>
    @elseif ($meta)
        <p class="mt-2 text-xs text-ink-500">학습 데이터가 부족해 규칙 기반으로 계산했습니다. 평가 곡선은 모델을 학습했을 때만 표시됩니다.</p>
    @else
        <p class="mt-2 text-xs text-ink-500">고객 화면에서 이탈 점수를 계산하면 모델 성능이 여기에 표시됩니다.</p>
    @endif

    @if ($workspace?->churn_scored_at)
        <p class="mt-2 text-[10px] text-ink-500">{{ $workspace->churn_scored_at->format('Y.m.d H:i') }} 기준</p>
    @endif
</a>
