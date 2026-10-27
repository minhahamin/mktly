<?php

use App\Jobs\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\TrackedLink;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\MessageRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function newCampaign(int $workspaceId, array $attrs = []): Campaign
{
    return Campaign::withoutGlobalScopes()->create($attrs + [
        'workspace_id' => $workspaceId,
        'name' => '테스트 캠페인',
        'channel' => 'email',
        'subject' => '{{name}}님 안녕하세요',
        'body' => "안녕 {{name}}\nhttps://example.test/shop",
        'status' => 'draft',
    ]);
}

function addContacts(int $workspaceId, int $count, array $attrs = []): void
{
    static $n = 0;

    foreach (range(1, $count) as $_) {
        $n++;
        Contact::withoutGlobalScopes()->create($attrs + ['workspace_id' => $workspaceId, 'email' => "send{$n}@example.test", 'name' => "수신자{$n}"]);
    }
}

function sentMailCount(): int
{
    return count(Mail::mailer('array')->getSymfonyTransport()->messages());
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    $this->sender = app(CampaignSender::class);
});

it('전체 고객을 대상으로 메시지와 추적 링크를 만들고 청크 잡을 등록한다', function () {
    Queue::fake();
    addContacts($this->workspace->id, 3);
    $campaign = newCampaign($this->workspace->id);

    expect($this->sender->start($campaign))->toBeTrue();

    expect($campaign->fresh()->status)->toBe('sending')
        ->and(CampaignMessage::where('campaign_id', $campaign->id)->where('status', 'pending')->count())->toBe(3)
        ->and(CampaignMessage::where('campaign_id', $campaign->id)->whereNull('token')->count())->toBe(0)
        ->and(TrackedLink::where('campaign_id', $campaign->id)->pluck('target_url')->all())->toBe(['https://example.test/shop']);

    Queue::assertPushed(SendCampaignChunk::class, 1);
});

it('500건 단위로 잡을 나눈다', function () {
    Queue::fake();
    addContacts($this->workspace->id, 1050);
    $campaign = newCampaign($this->workspace->id);

    $this->sender->start($campaign);

    Queue::assertPushed(SendCampaignChunk::class, 3);
});

it('세그먼트가 있으면 해당 고객에게만 보낸다', function () {
    Queue::fake();
    addContacts($this->workspace->id, 2, ['total_spent' => 1000]);
    addContacts($this->workspace->id, 3, ['total_spent' => 0]);
    $segment = Segment::withoutGlobalScopes()->create([
        'workspace_id' => $this->workspace->id,
        'name' => '구매자',
        'rules' => ['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => '>=', 'value' => 1]]],
    ]);
    $campaign = newCampaign($this->workspace->id, ['segment_id' => $segment->id]);

    expect($this->sender->recipientCount($campaign))->toBe(2);

    $this->sender->start($campaign);

    expect(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(2);
});

it('다른 워크스페이스 고객에게는 보내지 않는다', function () {
    Queue::fake();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    addContacts($this->workspace->id, 2);
    addContacts($other->id, 5);
    $campaign = newCampaign($this->workspace->id);

    $this->sender->start($campaign);

    expect(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(2);
});

it('대상이 없으면 예외를 던지고 캠페인을 바꾸지 않는다', function () {
    $campaign = newCampaign($this->workspace->id);

    expect(fn () => $this->sender->start($campaign))->toThrow(DomainException::class);
    expect($campaign->fresh()->status)->toBe('draft');
});

it('이미 시작된 캠페인은 다시 시작하지 않는다', function () {
    Queue::fake();
    addContacts($this->workspace->id, 2);
    $campaign = newCampaign($this->workspace->id);

    expect($this->sender->start($campaign))->toBeTrue()
        ->and($this->sender->start($campaign->fresh()))->toBeFalse();

    expect(CampaignMessage::where('campaign_id', $campaign->id)->count())->toBe(2);
    Queue::assertPushed(SendCampaignChunk::class, 1);
});

it('잡이 메일을 보내고 모든 메시지를 발송 완료로 기록하며 캠페인을 마감한다', function () {
    addContacts($this->workspace->id, 3);
    $campaign = newCampaign($this->workspace->id);
    $before = sentMailCount();

    $this->sender->start($campaign);   // QUEUE_CONNECTION=sync 이므로 즉시 실행된다.

    expect(sentMailCount() - $before)->toBe(3)
        ->and(CampaignMessage::where('campaign_id', $campaign->id)->where('status', 'sent')->count())->toBe(3)
        ->and($campaign->fresh()->status)->toBe('sent')
        ->and($campaign->fresh()->sent_at)->not->toBeNull();
});

it('발송된 메일은 수신자별로 치환되고 추적 URL을 담는다', function () {
    addContacts($this->workspace->id, 1);
    $campaign = newCampaign($this->workspace->id);
    $before = sentMailCount();

    $this->sender->start($campaign);

    $message = collect(Mail::mailer('array')->getSymfonyTransport()->messages())->slice($before)->first()->getOriginalMessage();
    $token = CampaignMessage::where('campaign_id', $campaign->id)->value('token');

    expect($message->getSubject())->toContain('수신자')->not->toContain('{{')
        ->and($message->getHtmlBody())->toContain('/t/c/')->toContain('?m='.$token)->toContain('/t/o/'.$token.'.gif');
});

it('SMS와 푸시는 실제 전송 없이 발송 완료로 기록한다', function () {
    addContacts($this->workspace->id, 2);
    $campaign = newCampaign($this->workspace->id, ['channel' => 'sms']);
    $before = sentMailCount();

    $this->sender->start($campaign);

    expect(sentMailCount())->toBe($before)->and($campaign->fresh()->status)->toBe('sent');
});

it('일부 수신자 발송이 실패해도 나머지는 계속 보내고 실패 사유를 기록한다', function () {
    addContacts($this->workspace->id, 1);
    // DB에는 들어가 있지만 메일 주소로는 쓸 수 없는 고객(발송 시 예외가 난다).
    addContacts($this->workspace->id, 1, ['email' => 'not an email']);
    $campaign = newCampaign($this->workspace->id);

    $this->sender->start($campaign);

    $failed = CampaignMessage::where('campaign_id', $campaign->id)->where('status', 'failed')->first();

    expect($failed)->not->toBeNull()
        ->and($failed->error)->not->toBeEmpty()
        ->and(CampaignMessage::where('campaign_id', $campaign->id)->where('status', 'sent')->count())->toBe(1)
        ->and($campaign->fresh()->status)->toBe('sent');
});

it('모든 메시지가 실패하면 캠페인을 실패로 마감한다', function () {
    addContacts($this->workspace->id, 2);
    $campaign = newCampaign($this->workspace->id);

    Mail::partialMock()->shouldReceive('html')->twice()->andThrow(new RuntimeException('거부됨'));

    $this->sender->start($campaign);

    expect($campaign->fresh()->status)->toBe('failed');
});

it('발송 중이 아닌 캠페인의 잡은 아무것도 보내지 않는다', function () {
    addContacts($this->workspace->id, 1);
    $campaign = newCampaign($this->workspace->id, ['status' => 'draft']);
    $contact = Contact::withoutGlobalScopes()->first();
    $message = CampaignMessage::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'token' => str_repeat('z', 40), 'status' => 'pending']);
    $before = sentMailCount();

    (new SendCampaignChunk($campaign->id, [$message->id]))->handle(app(MessageRenderer::class), $this->sender);

    expect(sentMailCount())->toBe($before)->and($message->fresh()->status)->toBe('pending');
});
