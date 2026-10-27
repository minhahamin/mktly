<?php

use App\Jobs\ScoreWorkspaceChurn;
use App\Livewire\Contacts\Index;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Services\Ai\BulkContactUpdater;
use App\Services\Ai\ChurnScorer;
use App\Services\Ai\ContactFeatureBuilder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function featureContact(int $workspaceId, string $email, array $attrs = []): Contact
{
    return Contact::withoutGlobalScopes()->create($attrs + ['workspace_id' => $workspaceId, 'email' => $email, 'name' => $email]);
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 't']);
});

// ---- 특징 추출 --------------------------------------------------------------

it('고객별 구매 최근성, 가입 기간, 구매액, 메일 반응을 계산한다', function () {
    $buyer = featureContact($this->workspace->id, 'buyer@e.test', [
        'joined_at' => now()->subDays(100)->toDateString(), 'last_purchase_at' => now()->subDays(10)->toDateString(), 'total_spent' => 50000,
    ]);
    $newbie = featureContact($this->workspace->id, 'new@e.test', ['joined_at' => now()->subDays(5)->toDateString()]);

    $campaign = Campaign::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'name' => 'c', 'subject' => 's', 'body' => 'b', 'status' => 'sent']);
    foreach ([[$buyer, now()], [$buyer, null], [$newbie, null]] as $i => [$contact, $opened]) {
        $c = $i === 1 ? Campaign::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'name' => 'c2', 'subject' => 's', 'body' => 'b', 'status' => 'sent']) : $campaign;
        CampaignMessage::create(['campaign_id' => $c->id, 'contact_id' => $contact->id, 'status' => 'sent', 'opened_at' => $opened]);
    }
    CampaignEvent::create(['campaign_id' => $campaign->id, 'contact_id' => $buyer->id, 'type' => 'click', 'created_at' => now()]);
    CampaignEvent::create(['campaign_id' => $campaign->id, 'contact_id' => $buyer->id, 'type' => 'open', 'created_at' => now()]);

    $rows = collect(app(ContactFeatureBuilder::class)->forWorkspace($this->workspace->id))->keyBy('id');

    expect($rows[$buyer->id])->toBe(['id' => $buyer->id, 'recency_days' => 10, 'tenure_days' => 100, 'total_spent' => 50000, 'received' => 2, 'opens' => 1, 'clicks' => 1])
        ->and($rows[$newbie->id])->toMatchArray(['recency_days' => null, 'tenure_days' => 5, 'received' => 1, 'opens' => 0, 'clicks' => 0]);
});

it('개인 식별 정보는 AI 서비스로 보내지 않는다', function () {
    featureContact($this->workspace->id, 'secret@e.test', ['name' => '비밀이름']);

    $rows = app(ContactFeatureBuilder::class)->forWorkspace($this->workspace->id);

    expect(array_keys($rows[0]))->toBe(['id', 'recency_days', 'tenure_days', 'total_spent', 'received', 'opens', 'clicks'])
        ->and(json_encode($rows))->not->toContain('secret@e.test')->not->toContain('비밀이름');
});

it('다른 워크스페이스 고객은 포함하지 않는다', function () {
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    featureContact($this->workspace->id, 'mine@e.test');
    featureContact($other->id, 'theirs@e.test');

    expect(app(ContactFeatureBuilder::class)->forWorkspace($this->workspace->id))->toHaveCount(1);
});

// ---- 점수 저장 ----------------------------------------------------------------

it('AI가 돌려준 이탈 점수를 고객에 저장하고 모델 지표를 기록한다', function () {
    $a = featureContact($this->workspace->id, 'a@e.test');
    $b = featureContact($this->workspace->id, 'b@e.test');
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    $foreign = featureContact($other->id, 'f@e.test');

    Http::fake(['ai.test/predict/churn' => Http::response([
        'scores' => [['id' => $a->id, 'score' => 0.9], ['id' => $b->id, 'score' => 0.1], ['id' => $foreign->id, 'score' => 0.5]],
        'metrics' => ['method' => 'logistic_regression', 'auc' => 0.83, 'trained_on' => 2, 'positive_rate' => 0.5, 'note' => '메모'],
    ])]);

    $metrics = app(ChurnScorer::class)->run($this->workspace->id);

    expect($metrics['auc'])->toBe(0.83)
        ->and($a->fresh()->churn_score)->toBe(0.9)->and($b->fresh()->churn_score)->toBe(0.1)
        // 다른 워크스페이스 고객은 응답에 섞여 와도 갱신되지 않는다.
        ->and($foreign->fresh()->churn_score)->toBeNull();

    $workspace = $this->workspace->fresh();
    expect($workspace->churn_scored_at)->not->toBeNull()->and($workspace->churn_meta['auc'])->toBe(0.83);
});

it('고객이 없으면 계산하지 않는다', function () {
    Http::fake();

    expect(fn () => app(ChurnScorer::class)->run($this->workspace->id))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('대량 갱신기는 허용된 컬럼만 수정한다', function () {
    $c = featureContact($this->workspace->id, 'x@e.test');

    app(BulkContactUpdater::class)->update($this->workspace->id, 'cluster_id', [$c->id => 3]);
    expect($c->fresh()->cluster_id)->toBe(3);

    expect(fn () => app(BulkContactUpdater::class)->update($this->workspace->id, 'email', [$c->id => 'hack']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(BulkContactUpdater::class)->update($this->workspace->id, 'cluster_id = 1; --', [$c->id => 1]))
        ->toThrow(InvalidArgumentException::class);
});

it('500건이 넘어도 모두 갱신한다', function () {
    $ids = collect(range(1, 520))->map(fn ($i) => featureContact($this->workspace->id, "bulk{$i}@e.test")->id);

    app(BulkContactUpdater::class)->update($this->workspace->id, 'churn_score', $ids->mapWithKeys(fn ($id) => [$id => 0.25])->all());

    expect(Contact::withoutGlobalScopes()->where('churn_score', 0.25)->count())->toBe(520);
});

// ---- 잡, 명령, 화면 -----------------------------------------------------------

it('매일 03시에 이탈 점수 계산 명령이 실행되도록 등록되어 있다', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command, 'contacts:score-churn'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 3 * * *');
});

it('명령은 모든 워크스페이스의 계산 잡을 등록한다', function () {
    Queue::fake();
    userInWorkspace(workspaceName: '두번째 스토어');

    $this->artisan('contacts:score-churn')->assertSuccessful();

    Queue::assertPushed(ScoreWorkspaceChurn::class, 2);
});

it('잡은 고객이 없는 워크스페이스를 조용히 건너뛴다', function () {
    Http::fake();

    (new ScoreWorkspaceChurn($this->workspace->id))->handle(app(ChurnScorer::class));

    Http::assertNothingSent();
});

it('고객 목록에 이탈 위험 등급과 마지막 계산 시각을 보여준다', function () {
    featureContact($this->workspace->id, 'high@e.test', ['name' => '위험고객', 'churn_score' => 0.91]);
    featureContact($this->workspace->id, 'low@e.test', ['name' => '안전고객', 'churn_score' => 0.12]);
    featureContact($this->workspace->id, 'none@e.test', ['name' => '미계산고객']);
    $this->workspace->forceFill(['churn_scored_at' => now(), 'churn_meta' => ['method' => 'logistic_regression', 'auc' => 0.81]])->save();

    $this->actingAs($this->user)->get('/contacts')->assertOk()
        ->assertSee('높음 91%')->assertSee('낮음 12%')->assertSee('이탈 점수 갱신')->assertSee('모델 AUC 0.81');
});

it('이탈 점수 갱신 버튼은 큐에 작업을 등록한다', function () {
    Queue::fake();

    Livewire::actingAs($this->user)->test(Index::class)->call('refreshChurn');

    Queue::assertPushed(ScoreWorkspaceChurn::class, fn ($job) => $job->workspaceId === $this->workspace->id);
});

it('viewer는 이탈 점수를 갱신할 수 없다', function () {
    [$viewer] = userInWorkspace('viewer');
    Queue::fake();

    Livewire::actingAs($viewer)->test(Index::class)->call('refreshChurn')->assertForbidden();
    Queue::assertNothingPushed();
});

it('이탈 위험 열로 정렬할 수 있다', function () {
    Livewire::actingAs($this->user)->test(Index::class)->call('sortBy', 'churn_score')->assertSet('sort', 'churn_score');
});
