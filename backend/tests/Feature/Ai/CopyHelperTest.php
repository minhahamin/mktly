<?php

use App\Livewire\Campaigns\Form;
use App\Models\AiGeneration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function fakeCopyResponse(bool $mock = true): void
{
    Http::fake(['*/copy/generate' => Http::response([
        'subjects' => ['제목 하나', '제목 둘', '제목 셋', '제목 넷', '제목 다섯'],
        'body' => '안녕하세요 {{name}}님, 본문 초안입니다.',
        'mock' => $mock,
    ])]);
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    RateLimiter::clear('ai-copy:'.$this->user->id);
});

it('제품 설명으로 제목 후보와 본문 초안을 받는다', function () {
    fakeCopyResponse();

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('aiProduct', '가을 니트')->set('aiTarget', '30대')->set('aiTone', 'playful')
        ->call('generateCopy')->assertHasNoErrors()
        ->assertSet('aiSubjects', ['제목 하나', '제목 둘', '제목 셋', '제목 넷', '제목 다섯'])
        ->assertSet('aiBody', '안녕하세요 {{name}}님, 본문 초안입니다.')
        ->assertSet('aiMock', true)->assertSee('샘플 결과');

    Http::assertSent(fn (Request $r) => $r['product'] === '가을 니트' && $r['target'] === '30대' && $r['tone'] === 'playful' && $r['channel'] === 'email');
});

it('생성 결과는 사용자가 고르기 전에는 폼에 반영되지 않는다', function () {
    fakeCopyResponse();

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('subject', '내가 쓴 제목')->set('aiProduct', '니트')->call('generateCopy')
        ->assertSet('subject', '내가 쓴 제목')->assertSet('body', '');
});

it('후보를 선택해 제목과 본문에 적용한다', function () {
    fakeCopyResponse();

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('aiProduct', '니트')->call('generateCopy')
        ->call('useSubject', 2)->assertSet('subject', '제목 셋')
        ->call('useBody')->assertSet('body', '안녕하세요 {{name}}님, 본문 초안입니다.')
        ->call('useSubject', 99)->assertSet('subject', '제목 셋');
});

it('생성 내역을 워크스페이스에 기록한다', function () {
    fakeCopyResponse();

    Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트')->call('generateCopy');

    $record = AiGeneration::withoutGlobalScopes()->first();
    expect($record->workspace_id)->toBe($this->workspace->id)
        ->and($record->user_id)->toBe($this->user->id)
        ->and($record->type)->toBe('copy')
        ->and($record->input['product'])->toBe('니트')
        ->and($record->output['subjects'])->toHaveCount(5)
        ->and($record->mock)->toBeTrue();
});

it('제품 설명은 필수이고 길이 제한이 있다', function () {
    Http::fake();

    Livewire::actingAs($this->user)->test(Form::class)
        ->call('generateCopy')->assertHasErrors(['aiProduct' => 'required'])
        ->set('aiProduct', str_repeat('가', 301))->call('generateCopy')->assertHasErrors(['aiProduct' => 'max'])
        ->set('aiProduct', '니트')->set('aiTone', 'angry')->call('generateCopy')->assertHasErrors(['aiTone']);

    Http::assertNothingSent();
});

it('AI 서비스 오류는 화면에 안내하고 내역을 남기지 않는다', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('aiProduct', '니트')->call('generateCopy')
        ->assertSet('aiError', 'AI 서비스에 연결할 수 없습니다. 잠시 후 다시 시도해 주세요.')
        ->assertSet('aiSubjects', []);

    expect(AiGeneration::withoutGlobalScopes()->count())->toBe(0);
});

it('사용자당 분당 10회로 호출을 제한한다', function () {
    fakeCopyResponse();
    $component = Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트');

    foreach (range(1, 10) as $_) {
        $component->call('generateCopy');
    }
    $component->call('generateCopy');

    expect($component->get('aiError'))->toContain('초 후에 다시 시도');
    Http::assertSentCount(10);
});

it('현재 선택한 채널을 AI 요청에 전달한다', function () {
    fakeCopyResponse();

    Livewire::actingAs($this->user)->test(Form::class)->set('channel', 'sms')->set('aiProduct', '니트')->call('generateCopy');

    Http::assertSent(fn (Request $r) => $r['channel'] === 'sms');
});

it('viewer는 AI 카피를 생성할 수 없다', function () {
    [$viewer] = userInWorkspace('viewer');

    $this->actingAs($viewer)->get('/campaigns/create')->assertForbidden();
});
