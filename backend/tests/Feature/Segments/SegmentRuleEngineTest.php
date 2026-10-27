<?php

use App\Models\Contact;
use App\Services\Segments\SegmentRuleEngine;

/** 고정 데이터로 엔진 동작을 검증한다. 오늘 기준 상대 일수를 사용한다. */
function seedEngineContacts(int $workspaceId): void
{
    $rows = [
        ['a@e.test', 500000, 5, 3, ['VIP', '신규']],
        ['b@e.test', 100000, 100, 200, ['뉴스레터']],
        ['c@e.test', 0, 400, null, null],
        ['d@e.test', 300000, 20, 50, ['VIP']],
    ];

    foreach ($rows as [$email, $spent, $joinedDaysAgo, $purchaseDaysAgo, $tags]) {
        Contact::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'email' => $email,
            'name' => $email,
            'total_spent' => $spent,
            'joined_at' => now()->subDays($joinedDaysAgo)->toDateString(),
            'last_purchase_at' => $purchaseDaysAgo === null ? null : now()->subDays($purchaseDaysAgo)->toDateString(),
            'tags' => $tags,
        ]);
    }
}

function engineEmails(array $rules): array
{
    return app(SegmentRuleEngine::class)->apply(Contact::query(), $rules)->orderBy('email')->pluck('email')->all();
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    seedEngineContacts($this->workspace->id);
    $this->actingAs($this->user);
});

it('숫자 비교 연산자를 적용한다', function () {
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => '>=', 'value' => 300000]]]))
        ->toBe(['a@e.test', 'd@e.test']);
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => '<=', 'value' => 100000]]]))
        ->toBe(['b@e.test', 'c@e.test']);
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => '=', 'value' => 0]]]))
        ->toBe(['c@e.test']);
});

it('가입일의 최근/이전 조건을 적용한다', function () {
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'joined_at', 'op' => 'within_days', 'value' => 30]]]))
        ->toBe(['a@e.test', 'd@e.test']);
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'joined_at', 'op' => 'older_than_days', 'value' => 90]]]))
        ->toBe(['b@e.test', 'c@e.test']);
});

it('마지막 구매일 조건과 구매 이력 없음을 구분한다', function () {
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'last_purchase_at', 'op' => 'older_than_days', 'value' => 90]]]))
        ->toBe(['b@e.test']);
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'last_purchase_at', 'op' => 'never']]]))
        ->toBe(['c@e.test']);
});

it('태그 포함/제외를 적용하고 태그가 없는 고객은 제외 조건에 포함된다', function () {
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'tags', 'op' => 'has', 'value' => 'VIP']]]))
        ->toBe(['a@e.test', 'd@e.test']);
    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'tags', 'op' => 'not_has', 'value' => 'VIP']]]))
        ->toBe(['b@e.test', 'c@e.test']);
});

it('AND와 OR 결합을 구분한다', function () {
    $conditions = [
        ['field' => 'tags', 'op' => 'has', 'value' => 'VIP'],
        ['field' => 'joined_at', 'op' => 'within_days', 'value' => 10],
    ];

    expect(engineEmails(['match' => 'all', 'conditions' => $conditions]))->toBe(['a@e.test'])
        ->and(engineEmails(['match' => 'any', 'conditions' => $conditions]))->toBe(['a@e.test', 'd@e.test']);
});

it('과거 형식(조건 배열)도 AND로 처리한다', function () {
    expect(engineEmails([['field' => 'total_spent', 'op' => '>=', 'value' => 300000]]))->toBe(['a@e.test', 'd@e.test']);
});

it('count는 미리보기 대상 수와 같다', function () {
    $rules = ['match' => 'all', 'conditions' => [['field' => 'tags', 'op' => 'has', 'value' => 'VIP']]];

    expect(app(SegmentRuleEngine::class)->count($rules))->toBe(2);
});

it('다른 워크스페이스의 고객은 대상에 포함되지 않는다', function () {
    [, $other] = userInWorkspace(workspaceName: '다른 스토어');
    Contact::withoutGlobalScopes()->create(['workspace_id' => $other->id, 'email' => 'z@e.test', 'name' => 'z', 'total_spent' => 999999999]);

    expect(engineEmails(['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => '>=', 'value' => 1]]]))
        ->not->toContain('z@e.test');
});

it('화이트리스트에 없는 항목과 연산자를 거부한다', function () {
    $engine = app(SegmentRuleEngine::class);

    expect(fn () => $engine->count(['match' => 'all', 'conditions' => [['field' => 'password', 'op' => '=', 'value' => 'x']]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $engine->count(['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => '; DROP TABLE', 'value' => 1]]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $engine->count(['match' => 'all', 'conditions' => [['field' => 'total_spent', 'op' => '>=', 'value' => '1 OR 1=1']]]))
        ->toThrow(InvalidArgumentException::class);
});

it('값 검증 규칙을 적용한다', function () {
    $engine = app(SegmentRuleEngine::class);

    expect($engine->isValidValue('total_spent', '1000'))->toBeTrue()
        ->and($engine->isValidValue('total_spent', '-1'))->toBeFalse()
        ->and($engine->isValidValue('total_spent', '1.5'))->toBeFalse()
        ->and($engine->isValidValue('churn_score', '0.7'))->toBeTrue()
        ->and($engine->isValidValue('churn_score', '1.5'))->toBeFalse()
        ->and($engine->isValidValue('tags', '  '))->toBeFalse();
});

it('조건을 사람이 읽는 문장으로 설명한다', function () {
    $engine = app(SegmentRuleEngine::class);

    expect($engine->describe(['field' => 'total_spent', 'op' => '>=', 'value' => 300000]))->toBe('누적 구매액 300,000원 이상')
        ->and($engine->describe(['field' => 'tags', 'op' => 'has', 'value' => 'VIP']))->toBe("태그 'VIP' 포함")
        ->and($engine->describe(['field' => 'last_purchase_at', 'op' => 'never']))->toBe('구매 이력 없음');
});
