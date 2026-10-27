<?php

use App\Livewire\Dashboard;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Contact;
use App\Services\Dashboard\DashboardStats;
use Livewire\Livewire;

/** 지정한 워크스페이스에 발송 완료 캠페인과 메시지/이벤트를 만든다. */
function sentCampaign(int $workspaceId, int $sent, int $opened, int $clicks, int $daysAgo = 2): Campaign
{
    $campaign = Campaign::withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId,
        'name' => "캠페인 {$workspaceId}",
        'subject' => '제목',
        'body' => '본문',
        'status' => 'sent',
        'sent_at' => now()->subDays($daysAgo),
    ]);

    for ($i = 1; $i <= $sent; $i++) {
        $contact = Contact::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'email' => "c{$campaign->id}-{$i}@example.test",
            'name' => "고객{$i}",
        ]);

        $campaign->messages()->create([
            'contact_id' => $contact->id,
            'status' => 'sent',
            'sent_at' => $campaign->sent_at,
            'opened_at' => $i <= $opened ? $campaign->sent_at->copy()->addHour() : null,
        ]);

        if ($i <= $opened) {
            $campaign->events()->create(['contact_id' => $contact->id, 'type' => CampaignEvent::OPEN, 'created_at' => $campaign->sent_at->copy()->addHour()]);
        }

        if ($i <= $clicks) {
            $campaign->events()->create(['contact_id' => $contact->id, 'type' => CampaignEvent::CLICK, 'created_at' => $campaign->sent_at->copy()->addHours(2)]);
        }
    }

    return $campaign;
}

it('발송 수, 오픈율, 클릭률을 계산한다', function () {
    [$user, $workspace] = userInWorkspace();
    sentCampaign($workspace->id, sent: 10, opened: 4, clicks: 2);

    $this->actingAs($user);
    $summary = app(DashboardStats::class)->summary(30);

    expect($summary['sent']['value'])->toBe(10)
        ->and($summary['open_rate']['value'])->toBe(40.0)
        ->and($summary['click_rate']['value'])->toBe(20.0);
});

it('직전 기간에 데이터가 없으면 증감률은 null이다', function () {
    [$user, $workspace] = userInWorkspace();
    sentCampaign($workspace->id, 5, 1, 0);

    $this->actingAs($user);

    expect(app(DashboardStats::class)->summary(7)['sent']['delta'])->toBeNull();
});

it('다른 워크스페이스의 데이터는 집계에 포함되지 않는다', function () {
    [$user, $workspace] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');

    sentCampaign($workspace->id, 3, 1, 1);
    sentCampaign($other->id, 50, 25, 10);

    $this->actingAs($user);

    expect(app(DashboardStats::class)->summary(30)['sent']['value'])->toBe(3)
        ->and(app(DashboardStats::class)->campaignComparison(30))->toHaveCount(1);
});

it('일별 추이는 빈 날짜를 0으로 채운다', function () {
    [$user, $workspace] = userInWorkspace();
    sentCampaign($workspace->id, 4, 2, 1, daysAgo: 1);

    $this->actingAs($user);
    $series = app(DashboardStats::class)->timeseries(7);

    expect($series['labels'])->toHaveCount(7)
        ->and(array_sum($series['sent']))->toBe(4)
        ->and(count($series['sent']))->toBe(7);
});

it('대시보드 화면이 지표를 보여준다', function () {
    [$user, $workspace] = userInWorkspace();
    sentCampaign($workspace->id, 10, 5, 1);

    $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('대시보드')->assertSee('50%');
});

it('허용되지 않은 기간 값은 30일로 보정한다', function () {
    [$user] = userInWorkspace();

    Livewire::actingAs($user)->test(Dashboard::class, ['days' => 5])->assertSet('days', 30);
    Livewire::actingAs($user)->test(Dashboard::class)->set('days', 7)->assertSet('days', 7)->set('days', 999)->assertSet('days', 30);
});
