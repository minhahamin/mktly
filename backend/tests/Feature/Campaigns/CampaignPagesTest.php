<?php

use App\Jobs\SendCampaignChunk;
use App\Livewire\Campaigns\Form;
use App\Livewire\Campaigns\Index;
use App\Livewire\Campaigns\Show;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Segment;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function pageCampaign(int $workspaceId, array $attrs = []): Campaign
{
    return Campaign::withoutGlobalScopes()->create($attrs + [
        'workspace_id' => $workspaceId, 'name' => '화면 캠페인', 'channel' => 'email', 'subject' => '제목', 'body' => '본문', 'status' => 'draft',
    ]);
}

function pageContacts(int $workspaceId, int $count): void
{
    static $n = 0;

    foreach (range(1, $count) as $_) {
        $n++;
        Contact::withoutGlobalScopes()->create(['workspace_id' => $workspaceId, 'email' => "page{$n}@example.test", 'name' => "고객{$n}"]);
    }
}

function validCampaignInput($component)
{
    return $component->set('name', '새 캠페인')->set('subject', '제목입니다')->set('body', '본문입니다');
}

// ---- 목록 -------------------------------------------------------------

it('캠페인 목록은 내 워크스페이스 캠페인만 보여준다', function () {
    [$user, $workspace] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    pageCampaign($workspace->id, ['name' => '내캠페인']);
    pageCampaign($other->id, ['name' => '남의캠페인']);

    $this->actingAs($user)->get('/campaigns')->assertOk()->assertSee('내캠페인')->assertDontSee('남의캠페인');
});

it('상태와 이름으로 필터링한다', function () {
    [$user, $workspace] = userInWorkspace();
    pageCampaign($workspace->id, ['name' => '가을초안', 'status' => 'draft']);
    pageCampaign($workspace->id, ['name' => '겨울발송', 'status' => 'sent']);

    Livewire::actingAs($user)->test(Index::class)
        ->set('status', 'sent')->assertSee('겨울발송')->assertDontSee('가을초안')
        ->set('status', '')->set('search', '가을')->assertSee('가을초안')->assertDontSee('겨울발송');
});

it('초안은 삭제할 수 있지만 발송 중 캠페인은 삭제할 수 없다', function () {
    [$user, $workspace] = userInWorkspace();
    $draft = pageCampaign($workspace->id);
    $sending = pageCampaign($workspace->id, ['status' => 'sending']);

    Livewire::actingAs($user)->test(Index::class)->call('confirmDelete', $draft->id)->call('delete');
    expect(Campaign::withoutGlobalScopes()->find($draft->id))->toBeNull();

    Livewire::actingAs($user)->test(Index::class)->call('confirmDelete', $sending->id)->assertForbidden();
    expect(Campaign::withoutGlobalScopes()->find($sending->id))->not->toBeNull();
});

// ---- 작성/수정 ----------------------------------------------------------

it('필수 입력을 검증한다', function () {
    [$user] = userInWorkspace();

    Livewire::actingAs($user)->test(Form::class)->call('saveDraft')->assertHasErrors(['name', 'subject', 'body']);
});

it('임시저장하면 draft 캠페인이 만들어지고 상세 화면으로 이동한다', function () {
    [$user, $workspace] = userInWorkspace();

    $component = validCampaignInput(Livewire::actingAs($user)->test(Form::class))->call('saveDraft')->assertHasNoErrors();

    $campaign = Campaign::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
    $component->assertRedirect('/campaigns/'.$campaign->id);
    expect($campaign->status)->toBe('draft')->and($campaign->segment_id)->toBeNull();
});

it('다른 워크스페이스의 세그먼트는 선택할 수 없다', function () {
    [$user] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    $foreign = Segment::withoutGlobalScopes()->create(['workspace_id' => $other->id, 'name' => '남의세그먼트', 'rules' => ['match' => 'all', 'conditions' => []]]);

    validCampaignInput(Livewire::actingAs($user)->test(Form::class))
        ->set('segment_id', $foreign->id)->call('saveDraft')->assertHasErrors(['segment_id']);
});

it('예약 발송은 미래 시각이어야 하고 대상이 있어야 한다', function () {
    [$user, $workspace] = userInWorkspace();

    // 대상 고객이 없는 상태
    validCampaignInput(Livewire::actingAs($user)->test(Form::class))
        ->set('scheduled_at', now()->addDay()->format('Y-m-d\TH:i'))->call('schedule')->assertHasErrors(['segment_id']);

    pageContacts($workspace->id, 2);

    validCampaignInput(Livewire::actingAs($user)->test(Form::class))
        ->set('scheduled_at', '')->call('schedule')->assertHasErrors(['scheduled_at' => 'required'])
        ->set('scheduled_at', now()->subHour()->format('Y-m-d\TH:i'))->call('schedule')->assertHasErrors(['scheduled_at' => 'after']);
});

it('예약하면 scheduled 상태와 예약 시각이 저장된다', function () {
    [$user, $workspace] = userInWorkspace();
    pageContacts($workspace->id, 2);
    $at = now()->addDay()->startOfMinute();

    validCampaignInput(Livewire::actingAs($user)->test(Form::class))
        ->set('scheduled_at', $at->format('Y-m-d\TH:i'))->call('schedule')->assertHasNoErrors();

    $campaign = Campaign::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
    expect($campaign->status)->toBe('scheduled')->and($campaign->scheduled_at->equalTo($at))->toBeTrue();
});

it('지금 발송은 확인 창을 거쳐 발송을 시작한다', function () {
    Queue::fake();
    [$user, $workspace] = userInWorkspace();
    pageContacts($workspace->id, 3);

    $component = validCampaignInput(Livewire::actingAs($user)->test(Form::class))
        ->call('requestSend')->assertSet('confirmingSend', true)->assertSee('3명');

    $component->call('confirmSend')->assertHasNoErrors();

    $campaign = Campaign::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
    $component->assertRedirect('/campaigns/'.$campaign->id);
    expect($campaign->status)->toBe('sending');
    Queue::assertPushed(SendCampaignChunk::class);
});

it('대상 고객이 없으면 발송 확인 창을 열지 않는다', function () {
    [$user] = userInWorkspace();

    validCampaignInput(Livewire::actingAs($user)->test(Form::class))
        ->call('requestSend')->assertSet('confirmingSend', false)->assertHasErrors(['segment_id']);
});

it('내용에 따라 대상 수와 변수 치환 미리보기를 보여준다', function () {
    [$user, $workspace] = userInWorkspace();
    Contact::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'email' => 'first@example.test', 'name' => '첫고객']);

    Livewire::actingAs($user)->test(Form::class)
        ->set('subject', '{{name}}님 혜택')->set('body', '안녕 {{name}}')
        ->assertSee('첫고객님 혜택')->assertSee('안녕 첫고객');
});

it('발송 완료된 캠페인은 수정 화면에 접근할 수 없다', function () {
    [$user, $workspace] = userInWorkspace();
    $sent = pageCampaign($workspace->id, ['status' => 'sent']);

    $this->actingAs($user)->get("/campaigns/{$sent->id}/edit")->assertForbidden();
});

it('기존 초안을 불러와 수정한다', function () {
    [$user, $workspace] = userInWorkspace();
    $campaign = pageCampaign($workspace->id, ['name' => '이전 이름']);

    Livewire::actingAs($user)->test(Form::class, ['campaign' => $campaign])
        ->assertSet('name', '이전 이름')->set('name', '새 이름')->call('saveDraft')->assertHasNoErrors();

    expect($campaign->fresh()->name)->toBe('새 이름');
});

it('viewer는 작성 화면에 접근할 수 없다', function () {
    [$viewer, $workspace] = userInWorkspace('viewer');
    $campaign = pageCampaign($workspace->id);

    $this->actingAs($viewer)->get('/campaigns')->assertOk()->assertDontSee('새 캠페인');
    $this->actingAs($viewer)->get('/campaigns/create')->assertForbidden();
    $this->actingAs($viewer)->get("/campaigns/{$campaign->id}/edit")->assertForbidden();
    $this->actingAs($viewer)->get("/campaigns/{$campaign->id}")->assertOk();
});

// ---- 상세 ---------------------------------------------------------------

it('상세 화면은 퍼널과 수신자를 보여주고 다른 워크스페이스 캠페인은 404다', function () {
    [$user, $workspace] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    $campaign = pageCampaign($workspace->id, ['status' => 'sent', 'name' => '상세 캠페인']);
    $foreign = pageCampaign($other->id);

    $this->actingAs($user)->get("/campaigns/{$campaign->id}")->assertOk()->assertSee('상세 캠페인')->assertSee('전환 퍼널');
    $this->actingAs($user)->get("/campaigns/{$foreign->id}")->assertNotFound();
});

it('예약된 캠페인은 취소하면 초안으로 돌아간다', function () {
    [$user, $workspace] = userInWorkspace();
    $campaign = pageCampaign($workspace->id, ['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);

    Livewire::actingAs($user)->test(Show::class, ['campaign' => $campaign])->call('cancelSchedule');

    expect($campaign->fresh())->status->toBe('draft')->scheduled_at->toBeNull();
});
