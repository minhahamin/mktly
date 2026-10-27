<?php

use App\Livewire\Segments\Suggest;
use App\Models\Contact;
use App\Models\Segment;
use App\Services\Ai\ClusterRecommender;
use App\Services\Segments\SegmentRuleEngine;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function clusterFake(array $contactIds): void
{
    Http::fake(['*/cluster' => Http::response([
        'assignments' => collect($contactIds)->values()->map(fn ($id, $i) => ['id' => $id, 'cluster' => $i % 2 + 1])->all(),
        'clusters' => [
            ['id' => 1, 'name' => '핵심 고객', 'description' => '구매액이 높은 고객', 'size' => 2, 'avg_spent' => 300000, 'avg_recency_days' => 12, 'open_rate' => 0.6],
            ['id' => 2, 'name' => '휴면 고객', 'description' => '오래 구매하지 않은 고객', 'size' => 2, 'avg_spent' => 10000, 'avg_recency_days' => null, 'open_rate' => 0.1],
        ],
    ])]);
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 't']);
    RateLimiter::clear('ai-cluster:'.$this->user->id);

    $this->ids = collect(range(1, 4))->map(fn ($i) => Contact::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'email' => "k{$i}@e.test", 'name' => "k{$i}"])->id)->all();
});

it('클러스터 결과를 고객에 저장하고 그룹 요약을 돌려준다', function () {
    clusterFake($this->ids);

    $clusters = app(ClusterRecommender::class)->run($this->workspace->id, 2);

    expect($clusters)->toHaveCount(2)->and($clusters[0]['name'])->toBe('핵심 고객');
    expect(Contact::withoutGlobalScopes()->whereIn('id', $this->ids)->orderBy('id')->pluck('cluster_id')->all())->toBe([1, 2, 1, 2]);
    Http::assertSent(fn (Request $r) => $r['k'] === 2 && count($r['contacts']) === 4);
});

it('k는 2에서 8 사이로 보정해 요청한다', function () {
    clusterFake($this->ids);

    app(ClusterRecommender::class)->run($this->workspace->id, 50);

    Http::assertSent(fn (Request $r) => $r['k'] === 8);
});

it('AI 추천 화면에서 분석을 실행하고 결과 카드를 보여준다', function () {
    clusterFake($this->ids);

    Livewire::actingAs($this->user)->test(Suggest::class)
        ->set('k', 2)->call('analyze')->assertHasNoErrors()
        ->assertSee('핵심 고객')->assertSee('휴면 고객')->assertSee('이력 없음');
});

it('클러스터를 세그먼트로 저장하면 클러스터 번호 조건이 만들어지고 실제 대상이 된다', function () {
    clusterFake($this->ids);

    Livewire::actingAs($this->user)->test(Suggest::class)
        ->call('analyze')->call('saveSegment', 1)->assertSet('saved', [1])
        ->call('saveSegment', 1);   // 같은 클러스터를 두 번 저장하지 않는다

    $segment = Segment::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->sole();
    expect($segment->name)->toBe('AI: 핵심 고객')
        ->and($segment->rules['conditions'][0])->toMatchArray(['field' => 'cluster_id', 'op' => '=', 'value' => 1]);

    $this->actingAs($this->user);
    expect(app(SegmentRuleEngine::class)->count($segment->rules))->toBe(2);
});

it('분석 결과에 없는 클러스터는 저장할 수 없다', function () {
    clusterFake($this->ids);

    Livewire::actingAs($this->user)->test(Suggest::class)->call('analyze')->call('saveSegment', 99);

    expect(Segment::withoutGlobalScopes()->count())->toBe(0);
});

it('고객 수 부족 등 AI 오류는 화면에 안내한다', function () {
    Http::fake(['*/cluster' => Http::response(['detail' => '클러스터링하려면 고객이 최소 6명 필요합니다.'], 422)]);

    Livewire::actingAs($this->user)->test(Suggest::class)
        ->call('analyze')->assertSet('clusters', null)->assertSee('최소 6명');
});

it('고객이 없으면 안내한다', function () {
    Http::fake();
    Contact::withoutGlobalScopes()->delete();

    Livewire::actingAs($this->user)->test(Suggest::class)->call('analyze')->assertSee('분석할 고객이 없습니다');
    Http::assertNothingSent();
});

it('분당 5회로 분석 호출을 제한한다', function () {
    clusterFake($this->ids);
    $component = Livewire::actingAs($this->user)->test(Suggest::class);

    foreach (range(1, 5) as $_) {
        $component->call('analyze');
    }
    $component->call('analyze');

    expect($component->get('error'))->toContain('초 후에 다시 시도');
    Http::assertSentCount(5);
});

it('viewer는 AI 추천 화면에 접근할 수 없다', function () {
    [$viewer] = userInWorkspace('viewer');

    $this->actingAs($viewer)->get('/segments/suggest')->assertForbidden();
});

it('세그먼트 목록에서 AI 추천 화면으로 이동할 수 있다', function () {
    $this->actingAs($this->user)->get('/segments')->assertSee('AI 추천')->assertSee('/segments/suggest');
});

it('이탈 확률 조건은 계산된 점수로 고객을 고른다', function () {
    Contact::withoutGlobalScopes()->whereKey($this->ids[0])->update(['churn_score' => 0.9]);
    Contact::withoutGlobalScopes()->whereKey($this->ids[1])->update(['churn_score' => 0.2]);
    $this->actingAs($this->user);

    $rules = ['match' => 'all', 'conditions' => [['field' => 'churn_score', 'op' => '>=', 'value' => 0.7]]];

    expect(app(SegmentRuleEngine::class)->count($rules))->toBe(1);
});
