<?php

namespace App\Livewire\Segments;

use App\Models\Segment;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\ClusterRecommender;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::app')]
#[Title('AI 세그먼트 추천')]
class Suggest extends Component
{
    public int $k = 4;

    /** @var list<array<string, mixed>>|null */
    public ?array $clusters = null;

    public ?string $error = null;

    /** 이번 분석 결과에서 세그먼트로 저장한 클러스터 번호 */
    public array $saved = [];

    public function mount(): void
    {
        $this->authorize('create', Segment::class);
    }

    public function analyze(ClusterRecommender $recommender): void
    {
        $this->authorize('create', Segment::class);
        $this->reset(['clusters', 'error', 'saved']);
        $this->k = max(2, min(8, $this->k));

        $key = 'ai-cluster:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->error = RateLimiter::availableIn($key).'초 후에 다시 시도해 주세요.';

            return;
        }

        RateLimiter::hit($key, 60);

        try {
            $this->clusters = $recommender->run(auth()->user()->currentWorkspaceId(), $this->k);
        } catch (AiUnavailableException|DomainException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** 클러스터를 "AI 클러스터 번호 = N" 조건의 세그먼트로 저장한다. */
    public function saveSegment(int $clusterId): void
    {
        $this->authorize('create', Segment::class);

        $cluster = collect($this->clusters ?? [])->firstWhere('id', $clusterId);

        if ($cluster === null || in_array($clusterId, $this->saved, true)) {
            return;
        }

        Segment::create([
            'name' => 'AI: '.$cluster['name'],
            'rules' => ['match' => 'all', 'conditions' => [['field' => 'cluster_id', 'op' => '=', 'value' => $clusterId]]],
        ]);

        $this->saved[] = $clusterId;
        $this->dispatch('notify', message: "'AI: {$cluster['name']}' 세그먼트를 만들었습니다.");
    }

    public function render()
    {
        return view('livewire.segments.suggest');
    }
}
