<?php

use App\Jobs\EmbedBrandDocument;
use App\Livewire\BrandDocuments\Create;
use App\Livewire\BrandDocuments\Index;
use App\Livewire\Campaigns\Form;
use App\Models\BrandChunk;
use App\Models\BrandDocument;
use App\Services\Ai\AiClient;
use App\Services\Brand\BrandDocuments;
use App\Services\Brand\BrandGuide;
use App\Services\Brand\ChunkSplitter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** 문서와 조각을 만든다. $embedded가 null이면 임베딩이 없는 조각이다. */
function brandDocument(int $workspaceId, string $title = '톤앤매너', array $chunks = ['존댓말을 씁니다'], ?array $embedded = [0.5, 0.5], string $provider = 'hash'): BrandDocument
{
    $document = BrandDocument::withoutGlobalScopes()->create(['workspace_id' => $workspaceId, 'title' => $title, 'content' => implode("\n\n", $chunks)]);

    foreach ($chunks as $position => $content) {
        $chunk = BrandChunk::withoutGlobalScopes()->create(['workspace_id' => $workspaceId, 'brand_document_id' => $document->id, 'position' => $position, 'content' => $content]);

        if ($embedded !== null) {
            $chunk->forceFill(['embedding' => $embedded, 'embedding_provider' => $provider])->save();
        }
    }

    return $document;
}

beforeEach(function () {
    [$this->user, $this->workspace] = userInWorkspace();
    config(['services.ai.url' => 'http://ai.test', 'services.ai.token' => 'secret-token']);
    Cache::flush();
});

// ---- 조각 분할 --------------------------------------------------------------

it('짧은 문서는 한 조각이고 빈 문서는 조각이 없다', function () {
    $splitter = new ChunkSplitter;

    expect($splitter->split("첫 문단입니다.\n\n둘째 문단입니다."))->toBe(["첫 문단입니다.\n\n둘째 문단입니다."])
        ->and($splitter->split("  \n\n "))->toBe([]);
});

it('긴 문서는 문단 단위로 나누고 앞 조각의 끝을 겹쳐 넣는다', function () {
    $paragraphs = array_map(fn ($i) => str_repeat("문단{$i} 내용입니다. ", 12), range(1, 6));   // 문단당 약 130자

    $chunks = (new ChunkSplitter)->split(implode("\n\n", $paragraphs));

    expect(count($chunks))->toBeGreaterThan(1);

    foreach ($chunks as $i => $chunk) {
        expect(mb_strlen($chunk))->toBeLessThanOrEqual(ChunkSplitter::SIZE + ChunkSplitter::OVERLAP + 1);

        if ($i > 0) {
            $tail = trim(mb_substr($chunks[$i - 1], -ChunkSplitter::OVERLAP));
            expect(str_starts_with($chunk, $tail."\n"))->toBeTrue();
        }
    }
});

it('한 문단이 길면 문장 단위로, 한 문장이 길면 글자 수로 자른다', function () {
    $sentences = implode(' ', array_map(fn ($i) => "문장{$i}은 아주 중요한 설명입니다.", range(1, 60)));
    $oneLong = str_repeat('가', 1300);

    $splitter = new ChunkSplitter;

    foreach ([$splitter->split($sentences), $splitter->split($oneLong)] as $chunks) {
        expect(count($chunks))->toBeGreaterThan(1);

        foreach ($chunks as $chunk) {
            expect(mb_strlen($chunk))->toBeLessThanOrEqual(ChunkSplitter::SIZE + ChunkSplitter::OVERLAP + 1);
        }
    }
});

it('조각을 이어 붙이면 원문의 모든 글자가 들어 있다', function () {
    $text = implode("\n\n", array_map(fn ($i) => "문단{$i} ".str_repeat('내용', 100), range(1, 8)));

    $joined = implode("\n", (new ChunkSplitter)->split($text));

    foreach (range(1, 8) as $i) {
        expect($joined)->toContain("문단{$i} ");
    }
});

// ---- 등록 -------------------------------------------------------------------

it('문서를 등록하면 조각을 만들고 임베딩 작업을 등록한다', function () {
    Queue::fake();

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('title', '  톤앤매너  ')->set('content', "존댓말을 씁니다.\n\n느낌표를 쓰지 않습니다.")
        ->call('save')->assertHasNoErrors()->assertRedirect('/brand-documents');

    $document = BrandDocument::withoutGlobalScopes()->first();
    expect($document->title)->toBe('톤앤매너')
        ->and($document->workspace_id)->toBe($this->workspace->id)
        ->and($document->created_by)->toBe($this->user->id)
        ->and($document->chunks)->toHaveCount(1)
        ->and($document->chunks[0]->workspace_id)->toBe($this->workspace->id);
    Queue::assertPushed(EmbedBrandDocument::class, fn ($job) => $job->documentId === $document->id);
});

it('.md 파일을 올리면 파일 내용을 쓴다', function () {
    Queue::fake();

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('title', '가이드')->set('content', '무시되는 내용')
        ->set('file', UploadedFile::fake()->createWithContent('guide.md', "# 가이드\n\n파일 속 내용"))
        ->call('save')->assertHasNoErrors();

    expect(BrandDocument::withoutGlobalScopes()->first()->content)->toBe("# 가이드\n\n파일 속 내용");
});

it('입력을 검증한다', function () {
    Queue::fake();
    $component = Livewire::actingAs($this->user)->test(Create::class);

    $component->set('title', '')->set('content', '내용')->call('save')->assertHasErrors(['title']);
    $component->set('title', str_repeat('가', 101))->call('save')->assertHasErrors(['title']);
    $component->set('title', '제목')->set('content', '  ')->call('save')->assertHasErrors(['content']);
    $component->set('content', str_repeat('a', BrandDocuments::MAX_BYTES + 1))->call('save')->assertHasErrors(['content']);
    $component->set('content', '내용')->set('file', UploadedFile::fake()->createWithContent('guide.pdf', 'x'))->call('save')->assertHasErrors(['file']);

    expect(BrandDocument::withoutGlobalScopes()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('UTF-8이 아닌 파일은 거부한다', function () {
    Queue::fake();

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('title', '제목')->set('file', UploadedFile::fake()->createWithContent('bad.txt', "\xFF\xFE\xFA 깨진 내용"))
        ->call('save')->assertHasErrors(['file']);

    expect(BrandDocument::withoutGlobalScopes()->count())->toBe(0);
});

it('워크스페이스당 문서는 20개까지다', function () {
    Queue::fake();
    foreach (range(1, BrandDocuments::MAX_DOCUMENTS) as $i) {
        brandDocument($this->workspace->id, "문서{$i}");
    }

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('title', '하나 더')->set('content', '내용')->call('save')->assertHasErrors(['content']);

    expect(BrandDocument::withoutGlobalScopes()->count())->toBe(BrandDocuments::MAX_DOCUMENTS);
});

it('다른 워크스페이스의 문서는 한도에 포함하지 않는다', function () {
    Queue::fake();
    [, $other] = userInWorkspace('admin', '다른 스토어');
    foreach (range(1, BrandDocuments::MAX_DOCUMENTS) as $i) {
        brandDocument($other->id, "문서{$i}");
    }

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('title', '내 문서')->set('content', '내용')->call('save')->assertHasNoErrors();
});

it('임베딩 호출이 실패해도 등록은 끝난다', function () {
    Http::fake(['ai.test/embed' => Http::response(['detail' => 'boom'], 500)]);

    Livewire::actingAs($this->user)->test(Create::class)
        ->set('title', '제목')->set('content', '내용')->call('save')->assertHasNoErrors()->assertRedirect('/brand-documents');

    $chunk = BrandChunk::withoutGlobalScopes()->first();
    expect(BrandDocument::withoutGlobalScopes()->count())->toBe(1)->and($chunk->embedding)->toBeNull();
});

it('viewer는 등록 화면에 들어갈 수 없다', function () {
    [$viewer] = userInWorkspace('viewer', '읽기 전용');

    Livewire::actingAs($viewer)->test(Create::class)->assertForbidden();
});

// ---- 목록과 삭제 ------------------------------------------------------------

it('내 워크스페이스의 문서만 보여 주고 준비 상태를 표시한다', function () {
    [, $other] = userInWorkspace('admin', '다른 스토어');
    brandDocument($this->workspace->id, '준비된 문서', ['a', 'b']);
    brandDocument($this->workspace->id, '준비 중 문서', ['c'], embedded: null);
    brandDocument($other->id, '남의 문서');

    Livewire::actingAs($this->user)->test(Index::class)
        ->assertSee('준비된 문서')->assertSee('검색 가능')->assertSee('준비 중 문서')->assertSee('준비 중')->assertDontSee('남의 문서');
});

it('문서가 없으면 빈 상태를 보여 준다', function () {
    Livewire::actingAs($this->user)->test(Index::class)->assertSee('아직 등록한 브랜드 문서가 없습니다');
});

it('문서를 삭제하면 조각도 함께 지운다', function () {
    $document = brandDocument($this->workspace->id, '삭제할 문서', ['a', 'b']);

    Livewire::actingAs($this->user)->test(Index::class)
        ->call('confirmDelete', $document->id)->assertSet('deletingId', $document->id)
        ->call('delete')->assertSet('deletingId', null);

    expect(BrandDocument::withoutGlobalScopes()->count())->toBe(0)->and(BrandChunk::withoutGlobalScopes()->count())->toBe(0);
});

it('viewer는 목록은 보지만 삭제할 수 없다', function () {
    [$viewer, $workspace] = userInWorkspace('viewer', '읽기 전용');
    $document = brandDocument($workspace->id, '공개 문서');

    Livewire::actingAs($viewer)->test(Index::class)
        ->assertSee('공개 문서')->assertDontSee('문서 등록')
        ->call('confirmDelete', $document->id)->assertForbidden();

    expect(BrandDocument::withoutGlobalScopes()->count())->toBe(1);
});

it('다른 워크스페이스의 문서는 삭제할 수 없다', function () {
    [, $other] = userInWorkspace('admin', '다른 스토어');
    $document = brandDocument($other->id, '남의 문서');

    Livewire::actingAs($this->user)->test(Index::class)->call('confirmDelete', $document->id)->assertNotFound();

    expect(BrandDocument::withoutGlobalScopes()->count())->toBe(1);
});

// ---- 임베딩 작업 ------------------------------------------------------------

it('임베딩이 없는 조각만 64개씩 나눠 임베딩하고 공급자와 함께 저장한다', function () {
    $contents = array_map(fn ($i) => "조각{$i}", range(1, 70));
    $document = brandDocument($this->workspace->id, '긴 문서', $contents, embedded: null);
    $done = BrandChunk::withoutGlobalScopes()->where('brand_document_id', $document->id)->where('position', 0)->first();
    $done->forceFill(['embedding' => [9.5], 'embedding_provider' => 'hash'])->save();

    Http::fake(fn ($request) => Http::response([
        'vectors' => array_map(fn () => [0.123456789, 0.2], $request['texts']), 'provider' => 'gemini', 'dimensions' => 256,
    ]));

    (new EmbedBrandDocument($document->id))->handle(app(AiClient::class));

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => count($request['texts']) === 64);
    Http::assertSent(fn ($request) => count($request['texts']) === 5);
    $chunks = BrandChunk::withoutGlobalScopes()->where('brand_document_id', $document->id)->orderBy('position')->get();
    expect($chunks[0]->embedding)->toBe([9.5])                       // 이미 있던 것은 그대로
        ->and($chunks[1]->embedding)->toBe([0.12346, 0.2])
        ->and($chunks[69]->embedding_provider)->toBe('gemini');
});

it('replace이면 이미 있는 임베딩도 다시 만든다', function () {
    $document = brandDocument($this->workspace->id, '문서', ['a'], embedded: [9.5]);
    Http::fake(['ai.test/embed' => Http::response(['vectors' => [[0.5, 0.5]], 'provider' => 'openai', 'dimensions' => 256])]);

    (new EmbedBrandDocument($document->id, replace: true))->handle(app(AiClient::class));

    $chunk = BrandChunk::withoutGlobalScopes()->first();
    expect($chunk->embedding)->toBe([0.5, 0.5])->and($chunk->embedding_provider)->toBe('openai');
});

it('모든 조각에 임베딩이 있으면 AI 서비스를 호출하지 않는다', function () {
    $document = brandDocument($this->workspace->id);
    Http::fake();

    (new EmbedBrandDocument($document->id))->handle(app(AiClient::class));

    Http::assertNothingSent();
});

it('임베딩 재생성 명령은 임베딩이 빠진 브랜드 문서를 등록하고 --all이면 전부 등록한다', function () {
    Queue::fake();
    $missing = brandDocument($this->workspace->id, '빠진 문서', embedded: null);
    brandDocument($this->workspace->id, '완료된 문서');

    $this->artisan('mktly:embeddings:rebuild')->expectsOutputToContain('1건')->assertSuccessful();
    Queue::assertPushed(EmbedBrandDocument::class, 1);
    Queue::assertPushed(EmbedBrandDocument::class, fn ($job) => $job->documentId === $missing->id && $job->replace === false);

    $this->artisan('mktly:embeddings:rebuild', ['--all' => true])->expectsOutputToContain('2건')->assertSuccessful();
    Queue::assertPushed(EmbedBrandDocument::class, fn ($job) => $job->replace === true);
});

// ---- 카피 생성 연동 ---------------------------------------------------------

it('후보는 임베딩이 있는 내 워크스페이스의 조각만 문서 제목과 함께 담는다', function () {
    [, $other] = userInWorkspace('admin', '다른 스토어');
    $this->actingAs($this->user);
    $mine = brandDocument($this->workspace->id, '내 가이드', ['존댓말'], embedded: [0.1, 0.2], provider: 'gemini');
    brandDocument($this->workspace->id, '준비 중', ['x'], embedded: null);
    brandDocument($other->id, '남의 가이드');

    $candidates = app(BrandGuide::class)->candidates();

    expect($candidates)->toBe([[
        'id' => $mine->chunks[0]->id, 'document_title' => '내 가이드', 'content' => '존댓말',
        'vector' => [0.1, 0.2], 'embedding_provider' => 'gemini',
    ]]);
});

it('후보는 최신 조각부터 최대 200개다', function () {
    $this->actingAs($this->user);
    brandDocument($this->workspace->id, '큰 문서', array_map(fn ($i) => "조각{$i}", range(1, BrandGuide::MAX_CANDIDATES + 5)));

    $candidates = app(BrandGuide::class)->candidates();

    expect($candidates)->toHaveCount(BrandGuide::MAX_CANDIDATES)->and($candidates[0]['content'])->toBe('조각205');
});

it('카피 생성 요청에 브랜드 조각을 담고 참고한 브랜드 문서를 보여 준다', function () {
    $document = brandDocument($this->workspace->id, '톤앤매너 가이드', ['존댓말을 씁니다'], embedded: [0.1, 0.2], provider: 'gemini');
    $chunkId = $document->chunks[0]->id;
    Http::fake(['*/copy/generate' => Http::response([
        'subjects' => ['a'], 'body' => '본문', 'mock' => false, 'provider' => 'gemini', 'embedding_provider' => 'gemini',
        'references' => [
            ['kind' => 'brand', 'id' => $chunkId, 'title' => '가짜 제목', 'score' => 0.8],
            ['kind' => 'brand', 'id' => 999999, 'title' => '끼워 넣은 문서', 'score' => 0.7],
            ['kind' => 'campaign', 'id' => $chunkId, 'title' => '종류가 다른 id', 'open_rate' => 0.9, 'score' => 0.6],
        ],
    ])]);

    Livewire::actingAs($this->user)->test(Form::class)
        ->set('aiProduct', '니트')->call('generateCopy')
        ->assertSet('aiReferences', [['kind' => 'brand', 'id' => $chunkId, 'title' => '톤앤매너 가이드', 'open_rate' => null]])
        ->assertSee('참고한 자료')->assertSee('톤앤매너 가이드')->assertSee('브랜드 문서')
        ->assertDontSee('끼워 넣은 문서')->assertDontSee('가짜 제목')->assertDontSee('종류가 다른 id');

    Http::assertSent(fn ($request) => count($request['brand_chunks']) === 1
        && $request['brand_chunks'][0]['id'] === $chunkId
        && $request['brand_chunks'][0]['document_title'] === '톤앤매너 가이드'
        && $request['brand_chunks'][0]['vector'] === [0.1, 0.2]);
});

it('다른 워크스페이스의 브랜드 조각은 카피 요청에 실리지 않는다', function () {
    [, $other] = userInWorkspace('admin', '다른 스토어');
    brandDocument($other->id, '남의 가이드');
    Http::fake(['*/copy/generate' => Http::response(['subjects' => ['a'], 'body' => 'b', 'mock' => true, 'provider' => 'mock'])]);

    Livewire::actingAs($this->user)->test(Form::class)->set('aiProduct', '니트')->call('generateCopy');

    Http::assertSent(fn ($request) => $request['brand_chunks'] === []);
});
