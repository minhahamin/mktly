<?php

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
});

function setChurnMeta($workspace, array $meta): void
{
    $workspace->forceFill(['churn_scored_at' => now(), 'churn_meta' => $meta])->save();
}

it('모델 성능이 있으면 사이드바에 ROC 곡선과 지표를 보여준다', function () {
    setChurnMeta($this->workspace, [
        'method' => 'logistic_regression', 'auc' => 0.83, 'precision' => 0.94, 'recall' => 0.75,
        'roc' => [[0, 0], [0.2, 0.7], [1, 1]],
    ]);

    $this->actingAs($this->user)->get('/dashboard')->assertOk()
        ->assertSee('AI 이탈 모델')->assertSee('이탈 모델 ROC 곡선')
        ->assertSee('0.83')->assertSee('0.94')->assertSee('0.75')->assertSee('교차 검증');
});

it('차트 데이터에 곡선 점과 무작위 기준선이 들어간다', function () {
    setChurnMeta($this->workspace, ['method' => 'logistic_regression', 'auc' => 0.9, 'roc' => [[0, 0], [0.1, 0.8], [1, 1]]]);

    $html = $this->actingAs($this->user)->get('/dashboard')->getContent();

    expect($html)->toContain('0.8')->toContain('무작위')->toContain('u0022xy');
});

it('아직 계산하지 않았다면 안내 문구를 보여준다', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk()
        ->assertSee('AI 이탈 모델')->assertSee('이탈 점수를 계산하면 모델 성능이');
});

it('학습 데이터가 부족해 규칙 기반이면 곡선 대신 이유를 보여준다', function () {
    setChurnMeta($this->workspace, ['method' => 'heuristic', 'auc' => null, 'roc' => null]);

    $this->actingAs($this->user)->get('/dashboard')->assertSee('규칙 기반으로 계산했습니다')->assertDontSee('이탈 모델 ROC 곡선');
});

it('다른 워크스페이스의 성능은 보이지 않는다', function () {
    [$otherUser, $other] = userInWorkspace('admin', '다른 스토어');
    setChurnMeta($other, ['method' => 'logistic_regression', 'auc' => 0.77, 'precision' => 0.55, 'recall' => 0.44, 'roc' => [[0, 0], [1, 1]]]);

    $this->actingAs($this->user)->get('/dashboard')->assertDontSee('0.77')->assertSee('이탈 점수를 계산하면');
});

it('viewer도 모델 성능을 볼 수 있고 로그인 화면에는 나타나지 않는다', function () {
    setChurnMeta($this->workspace, ['method' => 'logistic_regression', 'auc' => 0.83, 'roc' => [[0, 0], [1, 1]]]);
    [$viewer] = userInWorkspace('viewer', '뷰어 스토어');
    $this->workspace->users()->attach($viewer->id, ['role' => 'viewer']);

    $this->actingAs($this->user)->get('/dashboard')->assertSee('AI 이탈 모델');
    auth()->logout();
    $this->get('/login')->assertDontSee('AI 이탈 모델');
});
