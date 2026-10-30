<?php

use App\Livewire\Images\Create;
use App\Livewire\Images\Index;
use App\Models\AiGeneration;
use App\Models\BrandChunk;
use App\Models\BrandDocument;
use App\Models\GeneratedImage;
use App\Services\Ai\AiUsage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** 1x1 PNG. 앞 8바이트가 PNG 시그니처이고 NUL 바이트도 들어 있어 이진 저장이 깨지는지 확인하기 좋다. */
function imgPng(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

function imgDraftResponse(int $count = 3, array $extra = []): array
{
    return $extra + [
        'slides' => array_map(fn ($i) => ['title' => "제목{$i}", 'body' => "본문{$i}"], range(1, $count)),
        'mock' => false, 'provider' => 'gemini', 'references' => [], 'embedding_provider' => 'hash',
    ];
}

function imgRenderResponse(int $count = 3, string $background = 'template'): array
{
    return [
        'images' => array_map(fn ($i) => ['index' => $i, 'width' => 1080, 'height' => 1080, 'png_base64' => base64_encode(imgPng())], range(0, $count - 1)),
        'background_provider' => $background,
    ];
}

/** 이미지 세트를 만든다. 반환값은 세트 uuid. */
function imgSet(int $workspaceId, int $count = 3, array $attrs = []): string
{
    $uuid = $attrs['set_uuid'] ?? (string) Str::uuid();

    foreach (range(0, $count - 1) as $i) {
        GeneratedImage::withoutGlobalScopes()->create($attrs + [
            'workspace_id' => $workspaceId, 'set_uuid' => $uuid, 'title' => '가을 코트', 'kind' => 'cardnews', 'slide_index' => $i, 'ratio' => '1:1',
            'slide_title' => '제목'.($i + 1), 'data' => imgPng(), 'width' => 1080, 'height' => 1080, 'background_provider' => 'template',
        ]);
    }

    return $uuid;
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret-token']);
    Cache::flush();
    RateLimiter::clear('ai-image:'.$this->user->id);
});

// ---- 초안 -------------------------------------------------------------------

it('주제로 슬라이드 문구 초안을 만들고 생성 기록을 남긴다', function () {
    Http::fake(['ai.test/cardnews/draft' => Http::response(imgDraftResponse(4))]);

    $component = Livewire::actingAs($this->user)->test(Create::class)
        ->set('topic', '  가을 신상 코트  ')->set('target', '30대')->set('tone', 'playful')->set('slideCount', 4)
        ->call('draft')->assertHasNoErrors()->assertSet('error', null);

    expect($component->get('slides'))->toHaveCount(4)->and($component->get('slides')[0])->toBe(['title' => '제목1', 'body' => '본문1'])
        ->and($component->get('aiProvider'))->toBe('gemini');
    Http::assertSent(fn ($r) => $r->url() === 'http://ai.test/cardnews/draft'
        && $r['topic'] === '가을 신상 코트' && $r['target'] === '30대' && $r['tone'] === 'playful' && $r['kind'] === 'cardnews'
        && $r['slide_count'] === 4 && $r['examples'] === [] && $r['brand_chunks'] === [] && $r['allow_openai'] === true && $r['allow_external_embedding'] === true);

    $record = AiGeneration::withoutGlobalScopes()->latest('id')->first();
    expect($record->type)->toBe('cardnews')->and($record->provider)->toBe('gemini')->and($record->workspace_id)->toBe($this->workspace->id)
        ->and($component->get('generationId'))->toBe($record->id);
});

it('샘플 모드 결과임을 표시한다', function () {
    Http::fake(['ai.test/cardnews/draft' => Http::response(imgDraftResponse(3, ['mock' => true, 'provider' => 'mock']))]);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '쿠폰')->call('draft')
        ->assertSet('aiMock', true)->assertSee('샘플 결과');
});

it('초안 입력을 검증한다', function () {
    Http::fake();
    $component = Livewire::actingAs($this->user)->test(Create::class);

    $component->set('topic', '')->call('draft')->assertHasErrors(['topic']);
    $component->set('topic', str_repeat('가', 301))->call('draft')->assertHasErrors(['topic']);
    $component->set('topic', '주제')->set('target', str_repeat('가', 201))->call('draft')->assertHasErrors(['target']);
    $component->set('target', '')->set('slideCount', 2)->call('draft')->assertHasErrors(['slideCount']);
    $component->set('slideCount', 7)->call('draft')->assertHasErrors(['slideCount']);
    $component->set('slideCount', 5)->set('tone', 'angry')->call('draft')->assertHasErrors(['tone']);
    $component->set('tone', 'friendly')->set('ratio', '3:2')->call('draft')->assertHasErrors(['ratio']);

    Http::assertNothingSent();
});

it('초안은 카피와 같은 일일 상한을 쓴다', function () {
    config(['mktly.ai.copy_daily_limit' => 2]);
    Http::fake(['ai.test/cardnews/draft' => Http::response(imgDraftResponse())]);
    foreach (['copy', 'cardnews'] as $type) {
        AiGeneration::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'type' => $type, 'input' => [], 'output' => [], 'mock' => false, 'provider' => 'gemini']);
    }

    expect(app(AiUsage::class)->copyRemainingToday())->toBe(0);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '쿠폰')->call('draft')
        ->assertSet('error', '오늘 AI 생성 한도를 모두 사용했습니다. 내일 다시 시도해 주세요.')->assertSet('slides', []);

    Http::assertNothingSent();
});

it('OpenAI 상한에 도달하면 초안에서도 OpenAI를 건너뛰라고 알린다', function () {
    config(['mktly.ai.openai_daily_limit' => 1]);
    AiGeneration::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'type' => 'cardnews', 'input' => [], 'output' => [], 'mock' => false, 'provider' => 'openai']);
    Http::fake(['ai.test/cardnews/draft' => Http::response(imgDraftResponse())]);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '쿠폰')->call('draft');

    Http::assertSent(fn ($r) => $r['allow_openai'] === false);
});

it('AI 서비스를 쓸 수 없으면 안내 문구를 보여 준다', function () {
    Http::fake(['ai.test/cardnews/draft' => Http::response(['detail' => '오늘 AI 생성 한도를 모두 사용했습니다.'], 502)]);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '쿠폰')->call('draft')
        ->assertSet('error', '오늘 AI 생성 한도를 모두 사용했습니다.')->assertSet('slides', []);
});

it('분당 10회까지만 요청할 수 있다', function () {
    Http::fake(['ai.test/cardnews/draft' => Http::response(imgDraftResponse())]);
    $component = Livewire::actingAs($this->user)->test(Create::class)->set('topic', '쿠폰');

    foreach (range(1, 10) as $_) {
        $component->call('draft');
    }
    $component->call('draft');

    expect($component->get('error'))->toContain('초 후에 다시 시도해 주세요');
    Http::assertSentCount(10);
});

it('참고한 캠페인과 브랜드 문서를 요청에 담고, 우리가 보낸 후보에 있던 것만 보여 준다', function () {
    $document = BrandDocument::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'title' => '톤앤매너', 'content' => '존댓말']);
    $chunk = BrandChunk::withoutGlobalScopes()->create(['workspace_id' => $this->workspace->id, 'brand_document_id' => $document->id, 'position' => 0, 'content' => '존댓말을 쓴다']);
    $chunk->forceFill(['embedding' => [0.1, 0.2], 'embedding_provider' => 'gemini'])->save();
    Http::fake(['ai.test/cardnews/draft' => Http::response(imgDraftResponse(3, ['references' => [
        ['kind' => 'brand', 'id' => $chunk->id, 'title' => '가짜 제목', 'score' => 0.8],
        ['kind' => 'brand', 'id' => 999999, 'title' => '끼워 넣은 문서', 'score' => 0.7],
    ]]))]);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '쿠폰')->call('draft')
        ->assertSet('aiReferences', [['kind' => 'brand', 'id' => $chunk->id, 'title' => '톤앤매너', 'open_rate' => null]])
        ->assertSee('참고한 자료')->assertSee('브랜드 문서')->assertDontSee('끼워 넣은 문서')->assertDontSee('가짜 제목');

    Http::assertSent(fn ($r) => count($r['brand_chunks']) === 1 && $r['brand_chunks'][0]['vector'] === [0.1, 0.2]);
});

it('종류를 바꾸면 비율과 이전 초안을 정리한다', function () {
    Http::fake(['ai.test/cardnews/draft' => Http::response(imgDraftResponse())]);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '쿠폰')->call('draft')->assertCount('slides', 3)
        ->set('kind', 'cover')->assertSet('ratio', '16:9')->assertSet('slides', [])->assertSet('generationId', null)
        ->set('kind', 'cardnews')->assertSet('ratio', '1:1')
        ->set('ratio', '4:5')->set('kind', 'cover')->set('kind', 'cardnews')->assertSet('ratio', '1:1');
});

it('장수를 바꾸면 이전 초안을 지운다', function () {
    Http::fake(['ai.test/cardnews/draft' => Http::response(imgDraftResponse())]);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '쿠폰')->call('draft')->assertCount('slides', 3)
        ->set('slideCount', 4)->assertSet('slides', []);
});

// ---- 이미지 만들기 ----------------------------------------------------------

it('고친 문구로 이미지를 그려 세트로 저장한다', function () {
    Http::fake([
        'ai.test/cardnews/draft' => Http::response(imgDraftResponse(3)),
        'ai.test/cardnews/render' => Http::response(imgRenderResponse(3)),
    ]);

    $component = Livewire::actingAs($this->user)->test(Create::class)->set('topic', '가을 코트')->set('ratio', '4:5')->call('draft');
    $generationId = $component->get('generationId');
    $component->set('slides.0.title', '  고친 제목  ')->call('generate')->assertHasNoErrors()->assertRedirect('/images');

    $images = GeneratedImage::withoutGlobalScopes()->orderBy('slide_index')->get();
    expect($images)->toHaveCount(3)
        ->and($images->pluck('set_uuid')->unique())->toHaveCount(1)
        ->and($images->pluck('slide_index')->all())->toBe([0, 1, 2])
        ->and($images[0]->data)->toBe(imgPng())
        ->and($images[0]->only(['workspace_id', 'user_id', 'ai_generation_id', 'title', 'kind', 'ratio', 'slide_title', 'width', 'height', 'background_provider']))->toBe([
            'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'ai_generation_id' => $generationId, 'title' => '가을 코트',
            'kind' => 'cardnews', 'ratio' => '4:5', 'slide_title' => '고친 제목', 'width' => 1080, 'height' => 1080, 'background_provider' => 'template',
        ]);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/cardnews/render')
        && $r['ratio'] === '4:5' && $r['kind'] === 'cardnews' && $r['seed'] === $images[0]->set_uuid
        && $r['slides'][0] === ['title' => '고친 제목', 'body' => '본문1'] && count($r['slides']) === 3);
});

it('대표 이미지는 한 장만 저장한다', function () {
    Http::fake([
        'ai.test/cardnews/draft' => Http::response(imgDraftResponse(1)),
        'ai.test/cardnews/render' => Http::response(imgRenderResponse(1)),
    ]);

    Livewire::actingAs($this->user)->test(Create::class)->set('kind', 'cover')->set('topic', '블로그 글')->call('draft')->call('generate')->assertHasNoErrors();

    expect(GeneratedImage::withoutGlobalScopes()->count())->toBe(1)->and(GeneratedImage::withoutGlobalScopes()->value('kind'))->toBe('cover');
});

// ---- AI 배경 삽화 -----------------------------------------------------------

function imgThreeSlides(): array
{
    return array_map(fn ($i) => ['title' => "제목{$i}", 'body' => ''], range(1, 3));
}

it('상한이 설정되지 않으면 AI 배경 선택지가 없고 요청하지도 않는다', function () {
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3))]);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '가을 코트')->set('slides', imgThreeSlides())
        ->assertDontSee('AI 배경 삽화')->set('aiBackground', true)->call('generate')->assertHasNoErrors();

    Http::assertSent(fn ($r) => $r['ai_background'] === false && $r['topic'] === '가을 코트');
});

it('AI 배경을 고르면 요청하고, AI 배경으로 그린 세트로 저장해 목록에 표시한다', function () {
    config(['mktly.ai.image_daily_limit' => 3]);
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3, 'gemini'))]);

    Livewire::actingAs($this->user)->test(Create::class)->set('topic', '가을 코트')->set('slides', imgThreeSlides())
        ->assertSee('AI 배경 삽화 사용 (오늘 남은 3회)')
        ->set('aiBackground', true)->call('generate')->assertHasNoErrors();

    Http::assertSent(fn ($r) => $r['ai_background'] === true && $r['topic'] === '가을 코트');
    expect(GeneratedImage::withoutGlobalScopes()->pluck('background_provider')->unique()->all())->toBe(['gemini'])
        ->and(app(AiUsage::class)->imageRemainingToday())->toBe(2);
    Livewire::actingAs($this->user)->test(Index::class)->assertSee('AI 배경');
});

it('AI 배경을 고르지 않으면 상한이 남아 있어도 요청하지 않는다', function () {
    config(['mktly.ai.image_daily_limit' => 3]);
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3))]);

    Livewire::actingAs($this->user)->test(Create::class)->set('slides', imgThreeSlides())->call('generate')->assertHasNoErrors();

    Http::assertSent(fn ($r) => $r['ai_background'] === false);
});

it('AI 배경 상한은 모든 워크스페이스 합계이고, 도달하면 기본 배경으로 그린다', function () {
    config(['mktly.ai.image_daily_limit' => 2]);
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3))]);
    [, $other] = userInWorkspace('admin', '다른 스토어');
    imgSet($other->id, 3, ['background_provider' => 'gemini']);
    imgSet($this->workspace->id, 1, ['background_provider' => 'gemini']);
    imgSet($this->workspace->id, 1);                                        // 템플릿 배경은 세지 않는다
    $yesterday = imgSet($other->id, 1, ['background_provider' => 'gemini']);
    GeneratedImage::withoutGlobalScopes()->where('set_uuid', $yesterday)->update(['created_at' => now()->subDay()]);  // 어제 것은 세지 않는다

    expect(app(AiUsage::class)->imageRemainingToday())->toBe(0);

    Livewire::actingAs($this->user)->test(Create::class)->set('slides', imgThreeSlides())
        ->assertSee('오늘 AI 배경 삽화 한도를 모두 사용해')
        ->set('aiBackground', true)->call('generate')->assertHasNoErrors();

    Http::assertSent(fn ($r) => $r['ai_background'] === false);
});

it('AI 배경을 만들지 못해 기본 배경으로 그려졌으면 그대로 저장하고 사용량에 세지 않는다', function () {
    config(['mktly.ai.image_daily_limit' => 1]);
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3, 'template'))]);

    Livewire::actingAs($this->user)->test(Create::class)->set('slides', imgThreeSlides())->set('aiBackground', true)->call('generate')->assertHasNoErrors();

    expect(GeneratedImage::withoutGlobalScopes()->value('background_provider'))->toBe('template')
        ->and(app(AiUsage::class)->imageRemainingToday())->toBe(1);
});

it('알 수 없는 배경 공급자 값은 기본 배경으로 기록한다', function () {
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3, '<script>'))]);

    Livewire::actingAs($this->user)->test(Create::class)->set('slides', imgThreeSlides())->call('generate')->assertHasNoErrors();

    expect(GeneratedImage::withoutGlobalScopes()->value('background_provider'))->toBe('template');
});

it('문구 입력을 검증한다', function () {
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3))]);
    $slides = fn (array $override = []) => array_replace_recursive(array_map(fn ($i) => ['title' => "제목{$i}", 'body' => '본문'], range(1, 3)), $override);
    $component = Livewire::actingAs($this->user)->test(Create::class);

    $component->call('generate')->assertHasErrors(['slides']);
    $component->set('slides', $slides([1 => ['title' => '']]))->call('generate')->assertHasErrors(['slides.1.title']);
    $component->set('slides', $slides([0 => ['title' => str_repeat('가', 21)]]))->call('generate')->assertHasErrors(['slides.0.title']);
    $component->set('slides', $slides([2 => ['body' => str_repeat('가', 61)]]))->call('generate')->assertHasErrors(['slides.2.body']);
    $component->set('slides', array_slice($slides(), 0, 2))->call('generate')->assertHasErrors(['slides']);
    $component->set('kind', 'cover')->set('slides', $slides())->call('generate')->assertHasErrors(['slides']);

    Http::assertNothingSent();
    expect(GeneratedImage::withoutGlobalScopes()->count())->toBe(0);
});

it('AI 서비스가 PNG가 아니거나 장 수가 다른 응답을 주면 저장하지 않는다', function (array $response) {
    Http::fake(['ai.test/cardnews/render' => Http::response($response)]);

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('slides', array_map(fn ($i) => ['title' => "제목{$i}", 'body' => ''], range(1, 3)))
        ->call('generate')->assertSet('error', '이미지를 만들지 못했습니다. 잠시 후 다시 시도해 주세요.')->assertNoRedirect();

    expect(GeneratedImage::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'PNG가 아닌 데이터' => fn () => ['images' => array_map(fn ($i) => ['width' => 1, 'height' => 1, 'png_base64' => base64_encode('<html>not a png</html>')], range(1, 3)), 'background_provider' => 'template'],
    'base64가 아닌 데이터' => fn () => ['images' => array_map(fn ($i) => ['width' => 1, 'height' => 1, 'png_base64' => '***'], range(1, 3)), 'background_provider' => 'template'],
    '장 수 불일치' => fn () => imgRenderResponse(2),
    '너무 큰 이미지' => fn () => ['images' => array_map(fn ($i) => ['width' => 1, 'height' => 1, 'png_base64' => base64_encode(substr(imgPng(), 0, 8).str_repeat('a', 2_000_001))], range(1, 3)), 'background_provider' => 'template'],
]);

it('이미지 렌더에 실패하면 안내 문구를 보여 준다', function () {
    Http::fake(['ai.test/cardnews/render' => Http::response(['detail' => 'boom'], 500)]);

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('slides', array_map(fn ($i) => ['title' => "제목{$i}", 'body' => ''], range(1, 3)))
        ->call('generate')->assertSet('error', 'AI 서비스가 요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.');

    expect(GeneratedImage::withoutGlobalScopes()->count())->toBe(0);
});

it('보관 한도(30세트)에 도달하면 만들지 않는다', function () {
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3))]);
    foreach (range(1, GeneratedImage::MAX_SETS) as $_) {
        imgSet($this->workspace->id, 1);
    }

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('slides', array_map(fn ($i) => ['title' => "제목{$i}", 'body' => ''], range(1, 3)))
        ->call('generate')->assertSet('error', fn ($message) => str_contains($message, '최대 30개'));

    Http::assertNothingSent();
});

it('다른 워크스페이스의 세트는 보관 한도에 포함하지 않는다', function () {
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3))]);
    [, $other] = userInWorkspace('admin', '다른 스토어');
    foreach (range(1, GeneratedImage::MAX_SETS) as $_) {
        imgSet($other->id, 1);
    }

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('slides', array_map(fn ($i) => ['title' => "제목{$i}", 'body' => ''], range(1, 3)))
        ->call('generate')->assertHasNoErrors()->assertRedirect('/images');
});

it('다른 워크스페이스의 생성 기록 id는 연결하지 않는다', function () {
    Http::fake(['ai.test/cardnews/render' => Http::response(imgRenderResponse(3))]);
    [, $other] = userInWorkspace('admin', '다른 스토어');
    $foreign = AiGeneration::withoutGlobalScopes()->create(['workspace_id' => $other->id, 'type' => 'cardnews', 'input' => [], 'output' => [], 'mock' => true, 'provider' => 'mock']);

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('slides', array_map(fn ($i) => ['title' => "제목{$i}", 'body' => ''], range(1, 3)))
        ->set('generationId', $foreign->id)->call('generate')->assertHasNoErrors();

    expect(GeneratedImage::withoutGlobalScopes()->value('ai_generation_id'))->toBeNull();
});

it('viewer는 만들기 화면에 들어갈 수 없다', function () {
    [$viewer] = userInWorkspace('viewer', '읽기 전용');

    Livewire::actingAs($viewer)->test(Create::class)->assertForbidden();
});

// ---- 목록과 삭제 ------------------------------------------------------------

it('내 워크스페이스의 세트만 장 수와 함께 보여 준다', function () {
    [, $other] = userInWorkspace('admin', '다른 스토어');
    imgSet($this->workspace->id, 3, ['title' => '내 카드뉴스']);
    imgSet($other->id, 2, ['title' => '남의 카드뉴스']);

    $component = Livewire::actingAs($this->user)->test(Index::class);

    $component->assertSee('내 카드뉴스')->assertSee('3장')->assertSee('ZIP 받기')->assertDontSee('남의 카드뉴스');
    expect($component->get('sets'))->toHaveCount(1)->and($component->get('sets')[0]['images'])->toHaveCount(3);
});

it('이미지가 없으면 빈 상태를 보여 준다', function () {
    Livewire::actingAs($this->user)->test(Index::class)->assertSee('아직 만든 이미지가 없습니다');
});

it('세트를 삭제하면 모든 장이 지워진다', function () {
    $uuid = imgSet($this->workspace->id, 3);
    $keep = imgSet($this->workspace->id, 2);

    Livewire::actingAs($this->user)->test(Index::class)->call('confirmDelete', $uuid)->assertSet('deletingSet', $uuid)->call('delete')->assertSet('deletingSet', null);

    expect(GeneratedImage::withoutGlobalScopes()->where('set_uuid', $uuid)->count())->toBe(0)
        ->and(GeneratedImage::withoutGlobalScopes()->where('set_uuid', $keep)->count())->toBe(2);
});

it('viewer는 목록은 보지만 삭제할 수 없다', function () {
    [$viewer, $workspace] = userInWorkspace('viewer', '읽기 전용');
    $uuid = imgSet($workspace->id, 1, ['title' => '공유 카드뉴스']);

    Livewire::actingAs($viewer)->test(Index::class)->assertSee('공유 카드뉴스')->assertDontSee('카드뉴스 만들기')
        ->call('confirmDelete', $uuid)->assertForbidden();

    expect(GeneratedImage::withoutGlobalScopes()->count())->toBe(1);
});

it('다른 워크스페이스의 세트는 삭제할 수 없다', function () {
    [, $other] = userInWorkspace('admin', '다른 스토어');
    $uuid = imgSet($other->id, 2);

    Livewire::actingAs($this->user)->test(Index::class)->call('confirmDelete', $uuid)->assertNotFound();

    expect(GeneratedImage::withoutGlobalScopes()->count())->toBe(2);
});

// ---- 파일 내려주기 ----------------------------------------------------------

it('이미지를 PNG로 내려주고 download 옵션이면 첨부로 내려준다', function () {
    $uuid = imgSet($this->workspace->id, 2);
    $image = GeneratedImage::withoutGlobalScopes()->where('set_uuid', $uuid)->where('slide_index', 1)->first();

    $inline = $this->actingAs($this->user)->get("/images/{$image->id}/file");
    $inline->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($inline->getContent())->toBe(imgPng())->and($inline->headers->get('Content-Disposition'))->toStartWith('inline;')->toContain('-2.png');

    $this->actingAs($this->user)->get("/images/{$image->id}/file?download=1")->assertOk()->assertDownload();
});

it('로그인하지 않으면 이미지를 받을 수 없다', function () {
    $uuid = imgSet($this->workspace->id, 1);
    $id = GeneratedImage::withoutGlobalScopes()->where('set_uuid', $uuid)->value('id');

    $this->get("/images/{$id}/file")->assertRedirect('/login');
    $this->get("/images/sets/{$uuid}/zip")->assertRedirect('/login');
});

it('다른 워크스페이스의 이미지는 받을 수 없다', function () {
    [, $other] = userInWorkspace('admin', '다른 스토어');
    $uuid = imgSet($other->id, 1);
    $id = GeneratedImage::withoutGlobalScopes()->where('set_uuid', $uuid)->value('id');

    $this->actingAs($this->user)->get("/images/{$id}/file")->assertNotFound();
    $this->actingAs($this->user)->get("/images/sets/{$uuid}/zip")->assertNotFound();
});

it('viewer도 이미지를 받을 수 있다', function () {
    [$viewer, $workspace] = userInWorkspace('viewer', '읽기 전용');
    $uuid = imgSet($workspace->id, 1);
    $id = GeneratedImage::withoutGlobalScopes()->where('set_uuid', $uuid)->value('id');

    $this->actingAs($viewer)->get("/images/{$id}/file")->assertOk();
});

it('세트를 ZIP으로 묶어 내려준다', function () {
    $uuid = imgSet($this->workspace->id, 3, ['kind' => 'cover']);

    $response = $this->actingAs($this->user)->get("/images/sets/{$uuid}/zip");

    $response->assertOk()->assertDownload();
    expect($response->headers->get('Content-Disposition'))->toContain('mktly-cover-');

    $path = tempnam(sys_get_temp_dir(), 'zipcheck');
    file_put_contents($path, $response->streamedContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue()
        ->and($zip->numFiles)->toBe(3)
        ->and([$zip->getNameIndex(0), $zip->getNameIndex(1), $zip->getNameIndex(2)])->toBe(['1.png', '2.png', '3.png'])
        ->and($zip->getFromName('2.png'))->toBe(imgPng());
    $zip->close();
    @unlink($path);
});

it('잘못된 형식이나 없는 세트의 ZIP 요청은 404다', function () {
    $this->actingAs($this->user)->get('/images/sets/not-a-uuid/zip')->assertNotFound();
    $this->actingAs($this->user)->get('/images/sets/'.Str::uuid().'/zip')->assertNotFound();
    $this->actingAs($this->user)->get('/images/999999/file')->assertNotFound();
});

it('PNG 본문은 모델 직렬화에 실리지 않고 목록 조회는 본문을 읽지 않는다', function () {
    imgSet($this->workspace->id, 1);

    $image = GeneratedImage::withoutGlobalScopes()->first();
    $listed = GeneratedImage::withoutGlobalScopes()->withoutData()->first();

    expect($image->toArray())->not->toHaveKey('data')
        ->and($listed->getAttributes())->not->toHaveKey('data');
});
