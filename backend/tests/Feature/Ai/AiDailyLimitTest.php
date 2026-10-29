<?php

use App\Livewire\Campaigns\Form;
use App\Models\AiGeneration;
use App\Services\Ai\AiUsage;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function limitGeneration(int $workspaceId, bool $mock, $createdAt = null, ?string $provider = null): void
{
    $record = AiGeneration::withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId, 'type' => 'copy', 'input' => ['product' => 'x'], 'output' => ['subjects' => []], 'mock' => $mock,
        'provider' => $provider ?? ($mock ? 'mock' : 'gemini'),
    ]);
    AiGeneration::withoutGlobalScopes()->whereKey($record->id)->update(['created_at' => $createdAt ?? now()]);
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    RateLimiter::clear('ai-copy:'.$this->user->id);
    Http::fake(['*/copy/generate' => Http::response(['subjects' => ['a', 'b', 'c', 'd', 'e'], 'body' => '본문', 'mock' => false])]);
});

it('한도를 설정하지 않으면 제한하지 않는다', function () {
    config(['mktly.ai.copy_daily_limit' => null]);

    expect(app(AiUsage::class)->copyRemainingToday())->toBeNull();
});

it('오늘 실제 모델로 만든 생성만 사용량으로 센다', function () {
    config(['mktly.ai.copy_daily_limit' => 10]);
    [, $other] = userInWorkspace('admin', '다른 스토어');

    limitGeneration($this->workspace->id, mock: false);
    limitGeneration($other->id, mock: false);                          // 다른 워크스페이스도 합산(전체 비용 보호)
    limitGeneration($this->workspace->id, mock: true);                 // 샘플 결과는 제외
    limitGeneration($this->workspace->id, mock: false, createdAt: now()->subDay());   // 어제는 제외

    expect(app(AiUsage::class)->copyRemainingToday())->toBe(8);
});

it('한도를 넘기면 0을 돌려준다', function () {
    config(['mktly.ai.copy_daily_limit' => 1]);
    limitGeneration($this->workspace->id, mock: false);
    limitGeneration($this->workspace->id, mock: false);

    expect(app(AiUsage::class)->copyRemainingToday())->toBe(0);
});

it('한도에 도달하면 AI 서비스를 호출하지 않고 안내한다', function () {
    config(['mktly.ai.copy_daily_limit' => 2]);
    limitGeneration($this->workspace->id, mock: false);
    limitGeneration($this->workspace->id, mock: false);

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('aiProduct', '니트')->call('generateCopy')
        ->assertSet('aiError', '오늘 AI 카피 생성 한도를 모두 사용했습니다. 내일 다시 시도해 주세요.')
        ->assertSet('aiSubjects', []);

    Http::assertNothingSent();
});

it('한도 안이면 생성하고 남은 횟수가 줄어든다', function () {
    config(['mktly.ai.copy_daily_limit' => 2]);

    $component = Livewire::actingAs($this->user)->test(Form::class)->assertSee('오늘 남은 생성 2회');
    $component->set('aiProduct', '니트')->call('generateCopy')->assertHasNoErrors()->assertSet('aiError', null);

    expect(app(AiUsage::class)->copyRemainingToday())->toBe(1);
    Livewire::actingAs($this->user)->test(Form::class)->assertSee('오늘 남은 생성 1회');
});

it('샘플 모드로 생성한 결과는 한도를 소모하지 않는다', function () {
    config(['mktly.ai.copy_daily_limit' => 1]);
    Http::swap(new Factory);   // beforeEach의 가짜 응답을 버리고 샘플 모드 응답으로 교체
    Http::fake(['*/copy/generate' => Http::response(['subjects' => ['a'], 'body' => '본문', 'mock' => true])]);

    $component = Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트');
    $component->call('generateCopy')->call('generateCopy')->call('generateCopy');

    expect(app(AiUsage::class)->copyRemainingToday())->toBe(1);
});

it('한도가 없으면 남은 횟수 문구가 나타나지 않는다', function () {
    config(['mktly.ai.copy_daily_limit' => null]);

    Livewire::actingAs($this->user)->test(Form::class)->assertDontSee('오늘 남은 생성');
});

it('OpenAI 상한을 설정하지 않으면 제한하지 않는다', function () {
    config(['mktly.ai.openai_daily_limit' => null]);

    expect(app(AiUsage::class)->openaiRemainingToday())->toBeNull();
});

it('오늘 OpenAI가 응답한 생성만 OpenAI 사용량으로 센다', function () {
    config(['mktly.ai.openai_daily_limit' => 5]);

    limitGeneration($this->workspace->id, mock: false, provider: 'openai');
    limitGeneration($this->workspace->id, mock: false, provider: 'gemini');                      // 무료 Gemini는 제외
    limitGeneration($this->workspace->id, mock: false, createdAt: now()->subDay(), provider: 'openai');   // 어제는 제외

    expect(app(AiUsage::class)->openaiRemainingToday())->toBe(4);
});

it('응답한 공급자를 기록한다', function () {
    Http::swap(new Factory);
    Http::fake(['*/copy/generate' => Http::response(['subjects' => ['a'], 'body' => '본문', 'mock' => false, 'provider' => 'gemini'])]);

    Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트')->call('generateCopy')
        ->assertSet('aiProvider', 'gemini')->assertSee('Gemini 응답');

    expect(AiGeneration::withoutGlobalScopes()->latest('id')->first()->provider)->toBe('gemini');
});

it('OpenAI 상한에 도달하면 AI 서비스에 OpenAI를 건너뛰라고 알린다', function () {
    config(['mktly.ai.openai_daily_limit' => 1]);
    limitGeneration($this->workspace->id, mock: false, provider: 'openai');

    Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트')->call('generateCopy');

    Http::assertSent(fn ($request) => $request['allow_openai'] === false);
});

it('OpenAI 상한 안이면 OpenAI 사용을 허용한다', function () {
    config(['mktly.ai.openai_daily_limit' => 3]);

    Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트')->call('generateCopy');

    Http::assertSent(fn ($request) => $request['allow_openai'] === true);
});
