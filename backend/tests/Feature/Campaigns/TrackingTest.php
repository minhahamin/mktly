<?php

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\Contact;
use App\Models\TrackedLink;

/** @return array{0: Campaign, 1: CampaignMessage, 2: TrackedLink} */
function trackingFixture(int $workspaceId, string $suffix = 'a'): array
{
    $campaign = Campaign::withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId, 'name' => "추적 {$suffix}", 'subject' => '제목', 'body' => '본문', 'status' => 'sent',
    ]);
    $contact = Contact::withoutGlobalScopes()->create(['workspace_id' => $workspaceId, 'email' => "t{$suffix}@example.test", 'name' => "추적{$suffix}"]);
    $message = CampaignMessage::create([
        'campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'token' => str_pad($suffix, 40, 'm'), 'status' => 'sent', 'sent_at' => now(),
    ]);
    $link = TrackedLink::create(['campaign_id' => $campaign->id, 'token' => str_pad($suffix, 40, 'l'), 'target_url' => 'https://example.test/landing']);

    return [$campaign, $message, $link];
}

beforeEach(function () {
    [, $this->workspace] = userInWorkspace();
});

it('오픈 픽셀은 GIF를 돌려주고 최초 1회만 오픈을 기록한다', function () {
    [$campaign, $message] = trackingFixture($this->workspace->id);

    $response = $this->get("/t/o/{$message->token}.gif");
    $this->get("/t/o/{$message->token}.gif");

    $response->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect(str_starts_with($response->getContent(), 'GIF89a'))->toBeTrue()
        ->and($message->fresh()->opened_at)->not->toBeNull()
        ->and(CampaignEvent::where('campaign_id', $campaign->id)->where('type', 'open')->count())->toBe(1);
});

it('알 수 없는 오픈 토큰도 같은 GIF로 응답하고 아무것도 기록하지 않는다', function () {
    $this->get('/t/o/'.str_repeat('x', 40).'.gif')->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect(CampaignEvent::count())->toBe(0);
});

it('형식이 잘못된 토큰은 404다', function () {
    $this->get('/t/o/short.gif')->assertNotFound();
    $this->get('/t/c/short')->assertNotFound();
});

it('클릭하면 저장된 URL로 리디렉션하고 클릭 이벤트를 수신자에게 연결한다', function () {
    [$campaign, $message, $link] = trackingFixture($this->workspace->id);

    $this->get("/t/c/{$link->token}?m={$message->token}")->assertRedirect('https://example.test/landing');

    $click = CampaignEvent::where('type', 'click')->first();
    expect($click->campaign_id)->toBe($campaign->id)
        ->and($click->contact_id)->toBe($message->contact_id)
        ->and($click->tracked_link_id)->toBe($link->id)
        // 클릭은 오픈도 함께 기록한다.
        ->and(CampaignEvent::where('type', 'open')->count())->toBe(1)
        ->and($message->fresh()->opened_at)->not->toBeNull();
});

it('같은 수신자가 여러 번 클릭하면 클릭은 매번 기록하고 오픈은 한 번만 기록한다', function () {
    [, $message, $link] = trackingFixture($this->workspace->id);

    $this->get("/t/c/{$link->token}?m={$message->token}");
    $this->get("/t/c/{$link->token}?m={$message->token}");

    expect(CampaignEvent::where('type', 'click')->count())->toBe(2)
        ->and(CampaignEvent::where('type', 'open')->count())->toBe(1);
});

it('수신자 토큰이 없거나 틀려도 리디렉션은 하되 이벤트는 기록하지 않는다', function () {
    [, , $link] = trackingFixture($this->workspace->id);

    $this->get("/t/c/{$link->token}")->assertRedirect('https://example.test/landing');
    $this->get("/t/c/{$link->token}?m=".str_repeat('q', 40))->assertRedirect('https://example.test/landing');

    expect(CampaignEvent::count())->toBe(0);
});

it('다른 캠페인의 수신자 토큰으로는 클릭이 기록되지 않는다', function () {
    [, , $link] = trackingFixture($this->workspace->id, 'a');
    [, $otherMessage] = trackingFixture($this->workspace->id, 'b');

    $this->get("/t/c/{$link->token}?m={$otherMessage->token}")->assertRedirect('https://example.test/landing');

    expect(CampaignEvent::count())->toBe(0);
});

it('알 수 없는 링크 토큰은 404이고 요청 파라미터로 목적지를 바꿀 수 없다', function () {
    [, $message, $link] = trackingFixture($this->workspace->id);

    $this->get('/t/c/'.str_repeat('x', 40))->assertNotFound();
    $this->get("/t/c/{$link->token}?m={$message->token}&url=https://evil.test&redirect=https://evil.test")
        ->assertRedirect('https://example.test/landing');
});
