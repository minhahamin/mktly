<?php

use App\Livewire\Contacts\Index;
use App\Models\Contact;
use Livewire\Livewire;

function makeContact(int $workspaceId, array $attrs = []): Contact
{
    static $n = 0;
    $n++;

    return Contact::withoutGlobalScopes()->create($attrs + [
        'workspace_id' => $workspaceId,
        'email' => "user{$n}@example.test",
        'name' => "고객{$n}",
    ]);
}

it('고객 목록을 보여주고 다른 워크스페이스 고객은 숨긴다', function () {
    [$user, $workspace] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    makeContact($workspace->id, ['name' => '보이는고객']);
    makeContact($other->id, ['name' => '숨겨진고객']);

    $this->actingAs($user)->get('/contacts')->assertOk()->assertSee('보이는고객')->assertDontSee('숨겨진고객');
});

it('이름과 이메일로 검색한다', function () {
    [$user, $workspace] = userInWorkspace();
    makeContact($workspace->id, ['name' => '김하늘', 'email' => 'sky@example.test']);
    makeContact($workspace->id, ['name' => '이바다', 'email' => 'sea@example.test']);

    Livewire::actingAs($user)->test(Index::class)
        ->set('search', '하늘')->assertSee('김하늘')->assertDontSee('이바다')
        ->set('search', 'sea@')->assertSee('이바다')->assertDontSee('김하늘');
});

it('검색어의 와일드카드 문자를 그대로 취급한다', function () {
    [$user, $workspace] = userInWorkspace();
    makeContact($workspace->id, ['name' => '일반고객']);

    Livewire::actingAs($user)->test(Index::class)->set('search', '%')->assertDontSee('일반고객');
});

it('태그로 필터링한다', function () {
    [$user, $workspace] = userInWorkspace();
    makeContact($workspace->id, ['name' => 'VIP고객', 'tags' => ['VIP']]);
    makeContact($workspace->id, ['name' => '일반고객', 'tags' => ['신규']]);

    Livewire::actingAs($user)->test(Index::class)->set('tag', 'VIP')->assertSee('VIP고객')->assertDontSee('일반고객');
});

it('정렬 방향을 토글하고 허용되지 않은 필드는 무시한다', function () {
    [$user] = userInWorkspace();

    Livewire::actingAs($user)->test(Index::class)
        ->call('sortBy', 'name')->assertSet('sort', 'name')->assertSet('direction', 'asc')
        ->call('sortBy', 'name')->assertSet('direction', 'desc')
        ->call('sortBy', 'password')->assertSet('sort', 'name');
});

it('15명씩 페이지를 나눈다', function () {
    [$user, $workspace] = userInWorkspace();
    foreach (range(1, 20) as $i) {
        makeContact($workspace->id, ['name' => sprintf('페이지고객%02d', $i)]);
    }

    Livewire::actingAs($user)->test(Index::class)
        ->assertSee('페이지고객15')->assertDontSee('페이지고객16')
        ->call('gotoPage', 2)->assertSee('페이지고객16')->assertDontSee('페이지고객15');
});

it('고객을 추가한다', function () {
    [$user, $workspace] = userInWorkspace();

    Livewire::actingAs($user)->test(Index::class)
        ->call('create')->assertSet('showForm', true)
        ->set('email', 'new@example.test')->set('name', '새고객')->set('tagsInput', 'VIP, 신규, VIP')->set('total_spent', 30000)
        ->call('save')->assertHasNoErrors()->assertSet('showForm', false);

    $contact = Contact::withoutGlobalScopes()->where('email', 'new@example.test')->first();
    expect($contact->workspace_id)->toBe($workspace->id)
        ->and($contact->tags)->toBe(['VIP', '신규'])
        ->and($contact->total_spent)->toBe(30000);
});

it('입력값을 검증한다', function () {
    [$user] = userInWorkspace();

    Livewire::actingAs($user)->test(Index::class)->call('create')
        ->set('email', 'not-an-email')->set('name', '')->set('total_spent', -5)
        ->call('save')->assertHasErrors(['email', 'name', 'total_spent']);
});

it('같은 워크스페이스에서는 이메일이 중복될 수 없지만 다른 워크스페이스는 허용한다', function () {
    [$user, $workspace] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    makeContact($workspace->id, ['email' => 'dup@example.test']);
    makeContact($other->id, ['email' => 'shared@example.test']);

    Livewire::actingAs($user)->test(Index::class)->call('create')
        ->set('email', 'dup@example.test')->set('name', '중복')->call('save')->assertHasErrors(['email' => 'unique'])
        ->set('email', 'shared@example.test')->call('save')->assertHasNoErrors();
});

it('고객 정보를 수정한다', function () {
    [$user, $workspace] = userInWorkspace();
    $contact = makeContact($workspace->id, ['name' => '이전이름']);

    Livewire::actingAs($user)->test(Index::class)
        ->call('edit', $contact->id)->assertSet('name', '이전이름')
        ->set('name', '새이름')->call('save')->assertHasNoErrors();

    expect($contact->fresh()->name)->toBe('새이름');
});

it('고객을 삭제한다', function () {
    [$user, $workspace] = userInWorkspace();
    $contact = makeContact($workspace->id);

    Livewire::actingAs($user)->test(Index::class)
        ->call('confirmDelete', $contact->id)->assertSet('deletingId', $contact->id)
        ->call('delete')->assertSet('deletingId', null);

    expect(Contact::withoutGlobalScopes()->find($contact->id))->toBeNull();
});

it('다른 워크스페이스의 고객은 수정하거나 삭제할 수 없다', function () {
    [$user] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    $foreign = makeContact($other->id);

    Livewire::actingAs($user)->test(Index::class)->call('edit', $foreign->id)->assertStatus(404);
    Livewire::actingAs($user)->test(Index::class)->call('confirmDelete', $foreign->id)->assertStatus(404);
    expect(Contact::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});

it('viewer는 조회만 가능하다', function () {
    [$viewer, $workspace] = userInWorkspace('viewer');
    $contact = makeContact($workspace->id, ['name' => '조회대상']);

    $this->actingAs($viewer)->get('/contacts')->assertOk()->assertSee('조회대상')->assertDontSee('고객 추가');

    Livewire::actingAs($viewer)->test(Index::class)->call('create')->assertForbidden();
    Livewire::actingAs($viewer)->test(Index::class)->call('confirmDelete', $contact->id)->assertForbidden();
});
