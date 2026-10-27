<?php

use App\Jobs\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\Contact;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

function dueCampaign(int $workspaceId, string $name, $scheduledAt): Campaign
{
    return Campaign::withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId, 'name' => $name, 'channel' => 'email', 'subject' => '제목', 'body' => '본문',
        'status' => 'scheduled', 'scheduled_at' => $scheduledAt,
    ]);
}

beforeEach(function () {
    [, $this->workspace] = userInWorkspace();
    Contact::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'email' => 'due@example.test', 'name' => '수신자']);
});

it('예약 시각이 지난 캠페인만 발송을 시작한다', function () {
    Queue::fake();
    $due = dueCampaign($this->workspace->id, '지난 예약', now()->subMinute());
    $future = dueCampaign($this->workspace->id, '미래 예약', now()->addHour());

    $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

    expect($due->fresh()->status)->toBe('sending')->and($future->fresh()->status)->toBe('scheduled');
    Queue::assertPushed(SendCampaignChunk::class, 1);
});

it('두 번 실행해도 같은 캠페인을 중복 발송하지 않는다', function () {
    Queue::fake();
    dueCampaign($this->workspace->id, '지난 예약', now()->subMinute());

    $this->artisan('campaigns:dispatch-scheduled');
    $this->artisan('campaigns:dispatch-scheduled');

    Queue::assertPushed(SendCampaignChunk::class, 1);
});

it('예약 시점에 대상이 없으면 실패로 표시한다', function () {
    Queue::fake();
    Contact::withoutGlobalScopes()->delete();
    $campaign = dueCampaign($this->workspace->id, '대상 없음', now()->subMinute());

    $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

    expect($campaign->fresh()->status)->toBe('failed');
    Queue::assertNothingPushed();
});

it('스케줄러에 매분 실행으로 등록되어 있다', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command, 'campaigns:dispatch-scheduled'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
});
