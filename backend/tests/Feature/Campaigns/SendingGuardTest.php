<?php

use App\Jobs\SendCampaignChunk;
use App\Livewire\Campaigns\Form;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function guardCampaign(int $workspaceId, string $channel = 'email'): Campaign
{
    return Campaign::withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId, 'name' => '체험 캠페인', 'channel' => $channel, 'subject' => '제목', 'body' => '본문', 'status' => 'draft',
    ]);
}

function guardContacts(int $workspaceId, int $count = 2): void
{
    static $n = 0;

    foreach (range(1, $count) as $_) {
        $n++;
        Contact::withoutGlobalScopes()->create(['workspace_id' => $workspaceId, 'email' => "guard{$n}@example.test", 'name' => "수신자{$n}"]);
    }
}

function disableSending(Workspace $workspace): void
{
    $workspace->forceFill(['sending_enabled' => false])->save();
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    guardContacts($this->workspace->id);
});

it('기존 워크스페이스는 기본으로 이메일 발송이 허용된다', function () {
    Queue::fake();

    expect(app(CampaignSender::class)->start(guardCampaign($this->workspace->id)))->toBeTrue();
    Queue::assertPushed(SendCampaignChunk::class);
});

it('발송이 꺼진 워크스페이스는 이메일 캠페인을 시작할 수 없고 캠페인은 바뀌지 않는다', function () {
    Queue::fake();
    disableSending($this->workspace);
    $campaign = guardCampaign($this->workspace->id);

    expect(fn () => app(CampaignSender::class)->start($campaign))->toThrow(DomainException::class, '체험 모드');

    expect($campaign->fresh()->status)->toBe('draft');
    Queue::assertNothingPushed();
});

it('SMS와 푸시(모의 발송)는 체험 모드에서도 쓸 수 있다', function () {
    Queue::fake();
    disableSending($this->workspace);

    expect(app(CampaignSender::class)->start(guardCampaign($this->workspace->id, 'sms')))->toBeTrue();
});

it('작성 화면이 체험 모드 안내를 보여주고 즉시 발송을 막는다', function () {
    disableSending($this->workspace);

    Livewire::actingAs($this->user)->test(Form::class)
        ->assertSee('체험 모드')
        ->set('name', '체험')->set('subject', '제목')->set('body', '본문')
        ->call('requestSend')->assertSet('confirmingSend', false)->assertHasErrors(['segment_id']);
});

it('체험 모드에서도 임시저장과 미리보기는 된다', function () {
    disableSending($this->workspace);

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('name', '체험')->set('subject', '제목')->set('body', '본문')->call('saveDraft')->assertHasNoErrors();

    expect(Campaign::withoutGlobalScopes()->where('workspace_id', $this->workspace->id)->exists())->toBeTrue();
});

it('발송이 허용된 워크스페이스에는 체험 안내가 나타나지 않는다', function () {
    Livewire::actingAs($this->user)->test(Form::class)->assertDontSee('체험 모드');
});

it('예약된 이메일 캠페인도 꺼진 워크스페이스에서는 실행 시점에 실패로 표시된다', function () {
    Queue::fake();
    disableSending($this->workspace);
    $campaign = guardCampaign($this->workspace->id);
    $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => now()->subMinute()])->save();

    $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

    expect($campaign->fresh()->status)->toBe('failed');
    Queue::assertNothingPushed();
});

// ---- 운영자 명령 -----------------------------------------------------------------

it('운영자 명령으로 워크스페이스의 이메일 발송을 켜고 끈다', function () {
    disableSending($this->workspace);

    $this->artisan('mktly:workspace-sending', ['workspace' => $this->workspace->id, 'state' => 'on'])->expectsOutputToContain('허용')->assertSuccessful();
    expect($this->workspace->fresh()->sending_enabled)->toBeTrue();

    $this->artisan('mktly:workspace-sending', ['workspace' => $this->workspace->id, 'state' => 'off'])->expectsOutputToContain('차단')->assertSuccessful();
    expect($this->workspace->fresh()->sending_enabled)->toBeFalse();
});

it('켠 뒤에는 실제로 발송을 시작할 수 있다', function () {
    Queue::fake();
    disableSending($this->workspace);
    $this->artisan('mktly:workspace-sending', ['workspace' => $this->workspace->id, 'state' => 'on'])->assertSuccessful();

    expect(app(CampaignSender::class)->start(guardCampaign($this->workspace->id)))->toBeTrue();
});

it('운영자 명령은 잘못된 입력을 거부한다', function () {
    $this->artisan('mktly:workspace-sending', ['workspace' => $this->workspace->id, 'state' => 'maybe'])->assertFailed();
    $this->artisan('mktly:workspace-sending', ['workspace' => 99999, 'state' => 'on'])->expectsOutputToContain('찾을 수 없습니다')->assertFailed();
});
