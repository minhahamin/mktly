<?php

use App\Livewire\Segments\Form;
use App\Livewire\Segments\Index;
use App\Models\Contact;
use App\Models\Segment;
use Livewire\Livewire;

function makeSegment(int $workspaceId, string $name = '테스트 세그먼트', ?array $rules = null): Segment
{
    return Segment::withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId,
        'name' => $name,
        'rules' => $rules ?? ['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => '>=', 'value' => 100]]],
    ]);
}

it('세그먼트 목록에 대상 수와 조건 요약을 보여준다', function () {
    [$user, $workspace] = userInWorkspace();
    Contact::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'email' => 'a@e.test', 'name' => 'a', 'total_spent' => 500]);
    makeSegment($workspace->id, 'VIP');

    $this->actingAs($user)->get('/segments')->assertOk()->assertSee('VIP')->assertSee('1명')->assertSee('누적 구매액 100원 이상');
});

it('다른 워크스페이스의 세그먼트는 목록과 편집에서 보이지 않는다', function () {
    [$user] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    $foreign = makeSegment($other->id, '남의세그먼트');

    $this->actingAs($user)->get('/segments')->assertDontSee('남의세그먼트');
    $this->actingAs($user)->get("/segments/{$foreign->id}/edit")->assertNotFound();
});

it('조건을 추가하고 저장하면 정규화된 규칙으로 저장된다', function () {
    [$user, $workspace] = userInWorkspace();

    Livewire::actingAs($user)->test(Form::class)
        ->set('name', '고액 VIP')
        ->set('conditions.0.value', '300000')
        ->call('addCondition')
        ->set('conditions.1.field', 'tags')
        ->set('conditions.1.value', 'VIP')
        ->call('save')->assertHasNoErrors()->assertRedirect('/segments');

    $segment = Segment::withoutGlobalScopes()->where('workspace_id', $workspace->id)->first();
    expect($segment->name)->toBe('고액 VIP')
        ->and($segment->rules['match'])->toBe('all')
        ->and($segment->rules['conditions'])->toHaveCount(2)
        ->and($segment->rules['conditions'][1])->toMatchArray(['field' => 'tags', 'op' => 'has', 'value' => 'VIP']);
});

it('항목을 바꾸면 연산자와 값이 해당 항목 기본값으로 초기화된다', function () {
    [$user] = userInWorkspace();

    Livewire::actingAs($user)->test(Form::class)
        ->set('conditions.0.value', '500')
        ->set('conditions.0.field', 'last_purchase_at')
        ->assertSet('conditions.0.op', 'within_days')
        ->assertSet('conditions.0.value', '');
});

it('이름과 조건 값을 검증한다', function () {
    [$user] = userInWorkspace();

    Livewire::actingAs($user)->test(Form::class)
        ->call('save')->assertHasErrors(['name', 'conditions.0.value'])
        ->set('name', '이름')->set('conditions.0.value', '-5')->call('save')->assertHasErrors(['conditions.0.value']);

    Livewire::actingAs($user)->test(Form::class)
        ->set('name', '이름')->call('removeCondition', 0)->call('save')->assertHasErrors(['conditions']);
});

it('구매 이력 없음 조건은 값 없이 저장된다', function () {
    [$user, $workspace] = userInWorkspace();

    Livewire::actingAs($user)->test(Form::class)
        ->set('name', '미구매')
        ->set('conditions.0.field', 'last_purchase_at')->set('conditions.0.op', 'never')
        ->call('save')->assertHasNoErrors();

    expect(Segment::withoutGlobalScopes()->where('workspace_id', $workspace->id)->exists())->toBeTrue();
});

it('저장 전에도 입력 중인 조건으로 대상 수를 미리 계산한다', function () {
    [$user, $workspace] = userInWorkspace();
    foreach ([100, 600, 900] as $i => $spent) {
        Contact::withoutGlobalScopes()->create(['workspace_id' => $workspace->id, 'email' => "p{$i}@e.test", 'name' => "p{$i}", 'total_spent' => $spent]);
    }

    Livewire::actingAs($user)->test(Form::class)
        ->set('conditions.0.value', '500')->assertSee('2')
        ->call('addCondition')->set('conditions.1.value', '')   // 값이 빈 조건은 미리보기에서 제외
        ->assertSee('값이 비어 있는 조건');
});

it('기존 세그먼트를 편집해 수정한다', function () {
    [$user, $workspace] = userInWorkspace();
    $segment = makeSegment($workspace->id, '이전 이름');

    Livewire::actingAs($user)->test(Form::class, ['segment' => $segment])
        ->assertSet('name', '이전 이름')->assertSet('conditions.0.field', 'total_spent')
        ->set('name', '새 이름')->set('match', 'any')->call('save')->assertHasNoErrors();

    $fresh = $segment->fresh();
    expect($fresh->name)->toBe('새 이름')->and($fresh->rules['match'])->toBe('any');
});

it('세그먼트를 삭제한다', function () {
    [$user, $workspace] = userInWorkspace();
    $segment = makeSegment($workspace->id);

    Livewire::actingAs($user)->test(Index::class)
        ->call('confirmDelete', $segment->id)->call('delete')->assertSet('deletingId', null);

    expect(Segment::withoutGlobalScopes()->find($segment->id))->toBeNull();
});

it('viewer는 조회만 가능하다', function () {
    [$viewer, $workspace] = userInWorkspace('viewer');
    $segment = makeSegment($workspace->id, '읽기전용');

    $this->actingAs($viewer)->get('/segments')->assertOk()->assertSee('읽기전용')->assertDontSee('세그먼트 만들기');
    $this->actingAs($viewer)->get('/segments/create')->assertForbidden();
    $this->actingAs($viewer)->get("/segments/{$segment->id}/edit")->assertForbidden();
    Livewire::actingAs($viewer)->test(Index::class)->call('confirmDelete', $segment->id)->assertForbidden();
});

it('다른 워크스페이스의 세그먼트는 삭제할 수 없다', function () {
    [$user] = userInWorkspace();
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    $foreign = makeSegment($other->id);

    Livewire::actingAs($user)->test(Index::class)->call('confirmDelete', $foreign->id)->assertStatus(404);
    expect(Segment::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});
