<?php

use App\Jobs\SendCampaignChunk;
use App\Livewire\Campaigns\Form;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Services\Campaigns\CampaignSender;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function quotaCampaign(int $workspaceId, string $channel = 'email', string $status = 'draft'): Campaign
{
    return Campaign::withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId, 'name' => '한도 캠페인', 'channel' => $channel, 'subject' => '제목', 'body' => '본문', 'status' => $status,
    ]);
}

function quotaContacts(int $workspaceId, int $count): array
{
    static $n = 0;
    $ids = [];

    foreach (range(1, $count) as $_) {
        $n++;
        $ids[] = Contact::withoutGlobalScopes()->create(['workspace_id' => $workspaceId, 'email' => "quota{$n}@example.test", 'name' => "수신자{$n}"])->id;
    }

    return $ids;
}

function quotaMessage(Campaign $campaign, int $contactId, string $status, $createdAt = null): void
{
    $message = CampaignMessage::create(['campaign_id' => $campaign->id, 'contact_id' => $contactId, 'status' => $status]);
    CampaignMessage::whereKey($message->id)->update(['created_at' => $createdAt ?? now()]);
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    $this->sender = app(CampaignSender::class);
});

it('한도를 설정하지 않으면 제한하지 않는다', function () {
    config(['mktly.mail.daily_limit' => null]);

    expect($this->sender->remainingEmailQuota())->toBeNull();

    quotaContacts($this->workspace->id, 3);
    Queue::fake();
    expect($this->sender->start(quotaCampaign($this->workspace->id)))->toBeTrue();
});

it('오늘 만든 이메일 메시지(대기, 발송 완료)만 사용량으로 센다', function () {
    config(['mktly.mail.daily_limit' => 10]);
    $ids = quotaContacts($this->workspace->id, 6);
    $email = quotaCampaign($this->workspace->id, 'email', 'sent');
    $sms = quotaCampaign($this->workspace->id, 'sms', 'sent');

    quotaMessage($email, $ids[0], 'sent');
    quotaMessage($email, $ids[1], 'pending');
    quotaMessage($email, $ids[2], 'failed');                    // 실패는 제외
    quotaMessage($email, $ids[3], 'sent', now()->subDay());     // 어제는 제외
    quotaMessage($sms, $ids[4], 'sent');                        // SMS는 제외

    expect($this->sender->remainingEmailQuota())->toBe(8);
});

it('남은 한도가 없으면 0을 돌려준다', function () {
    config(['mktly.mail.daily_limit' => 1]);
    $ids = quotaContacts($this->workspace->id, 3);
    $campaign = quotaCampaign($this->workspace->id, 'email', 'sent');
    foreach ($ids as $id) {
        quotaMessage($campaign, $id, 'sent');
    }

    expect($this->sender->remainingEmailQuota())->toBe(0);
});

it('대상이 남은 한도보다 많으면 발송을 시작하지 않고 캠페인을 바꾸지 않는다', function () {
    Queue::fake();
    config(['mktly.mail.daily_limit' => 2]);
    quotaContacts($this->workspace->id, 3);
    $campaign = quotaCampaign($this->workspace->id);

    expect(fn () => $this->sender->start($campaign))->toThrow(DomainException::class, '남은 이메일 발송 한도(2건)');

    expect($campaign->fresh()->status)->toBe('draft')
        ->and(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('대상이 남은 한도와 같으면 발송한다', function () {
    Queue::fake();
    config(['mktly.mail.daily_limit' => 3]);
    quotaContacts($this->workspace->id, 3);

    expect($this->sender->start(quotaCampaign($this->workspace->id)))->toBeTrue();
    Queue::assertPushed(SendCampaignChunk::class);

    // 방금 만든 대기 메시지 3건이 사용량으로 잡혀 남은 한도가 0이 된다.
    expect($this->sender->remainingEmailQuota())->toBe(0);
});

it('SMS와 푸시 캠페인에는 이메일 한도를 적용하지 않는다', function () {
    Queue::fake();
    config(['mktly.mail.daily_limit' => 1]);
    quotaContacts($this->workspace->id, 3);

    expect($this->sender->start(quotaCampaign($this->workspace->id, 'sms')))->toBeTrue();
});

it('작성 화면이 남은 한도를 보여주고 한도를 넘는 즉시 발송을 막는다', function () {
    config(['mktly.mail.daily_limit' => 2]);
    quotaContacts($this->workspace->id, 3);

    Livewire::actingAs($this->user)->test(Form::class)
        ->assertSee('오늘 남은 이메일 발송 한도 2건')->assertSee('한도보다 많아')
        ->set('name', '큰 캠페인')->set('subject', '제목')->set('body', '본문')
        ->call('requestSend')->assertSet('confirmingSend', false)->assertHasErrors(['segment_id']);
});

it('한도 안이면 작성 화면에서 확인 창이 열린다', function () {
    config(['mktly.mail.daily_limit' => 5]);
    quotaContacts($this->workspace->id, 3);

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('name', '작은 캠페인')->set('subject', '제목')->set('body', '본문')
        ->call('requestSend')->assertSet('confirmingSend', true);
});

it('한도가 없으면 작성 화면에 한도 문구가 나타나지 않는다', function () {
    config(['mktly.mail.daily_limit' => null]);

    Livewire::actingAs($this->user)->test(Form::class)->assertDontSee('남은 이메일 발송 한도');
});
