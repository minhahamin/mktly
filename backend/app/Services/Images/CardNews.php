<?php

namespace App\Services\Images;

use App\Models\AiGeneration;
use App\Models\GeneratedImage;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\AiUsage;
use App\Services\Ai\CopyExamples;
use App\Services\Ai\ReferenceLabels;
use App\Services\Brand\BrandGuide;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** 카드뉴스/대표 이미지: 문구 초안 생성(LLM)과 이미지 렌더(템플릿), 결과 저장. 현재 사용자의 워크스페이스 기준으로 동작한다. */
class CardNews
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** 이미지 한 장의 최대 크기. 템플릿 렌더 결과는 100KB 안팎이다. */
    private const MAX_IMAGE_BYTES = 2_000_000;

    public function __construct(
        private readonly AiClient $ai,
        private readonly AiUsage $usage,
        private readonly CopyExamples $examples,
        private readonly BrandGuide $brand,
        private readonly ReferenceLabels $labels,
    ) {}

    /**
     * 슬라이드 문구 초안을 만든다. 카피 생성과 같은 일일 상한을 쓰고, 생성 기록을 남긴다.
     *
     * @param  array{topic: string, target: string, tone: string, kind: string, slide_count: int}  $input
     * @return array{slides: list<array{title: string, body: string}>, mock: bool, provider: string|null, references: list<array<string, mixed>>, generation_id: int}
     *
     * @throws DomainException 일일 상한에 도달한 경우
     * @throws AiUnavailableException AI 서비스를 쓸 수 없는 경우
     */
    public function draft(array $input, ?int $userId): array
    {
        if ($this->usage->copyRemainingToday() === 0) {
            throw new DomainException('오늘 AI 생성 한도를 모두 사용했습니다. 내일 다시 시도해 주세요.');
        }

        $examples = $this->examples->candidates();
        $brandChunks = $this->brand->candidates();

        $result = $this->ai->cardnewsDraft(
            $input,
            allowOpenai: $this->usage->openaiRemainingToday() !== 0,
            examples: $examples,
            allowExternalEmbedding: $this->usage->embedRemainingToday() !== 0,
            brandChunks: $brandChunks,
        );

        $provider = $result['provider'] ?? ($result['mock'] ? 'mock' : null);

        $generation = AiGeneration::create([
            'user_id' => $userId,
            'type' => AiGeneration::TYPE_CARDNEWS,
            'input' => $input,
            'output' => $result,
            'provider' => $provider,
            'mock' => $result['mock'],
        ]);

        return [
            'slides' => array_map(fn ($slide) => ['title' => (string) $slide['title'], 'body' => (string) ($slide['body'] ?? '')], $result['slides']),
            'mock' => (bool) $result['mock'],
            'provider' => $provider,
            'references' => $this->labels->resolve($result['references'] ?? [], $examples, $brandChunks),
            'generation_id' => $generation->id,
        ];
    }

    /**
     * 슬라이드를 PNG로 그려 저장하고 세트 id(uuid)를 돌려준다.
     *
     * @param  list<array{title: string, body: string}>  $slides
     *
     * @throws DomainException 보관 한도를 넘었거나 AI 서비스가 쓸 수 없는 이미지를 돌려준 경우
     * @throws AiUnavailableException AI 서비스를 쓸 수 없는 경우
     */
    public function render(array $slides, string $kind, string $ratio, string $title, ?int $generationId, ?int $userId): string
    {
        if (GeneratedImage::query()->distinct()->count('set_uuid') >= GeneratedImage::MAX_SETS) {
            throw new DomainException('이미지 세트는 워크스페이스당 최대 '.GeneratedImage::MAX_SETS.'개까지 보관할 수 있습니다. 쓰지 않는 세트를 삭제해 주세요.');
        }

        $setUuid = (string) Str::uuid();   // 같은 값을 배경 seed로 써서 세트 안의 장들이 한 가족처럼 보이게 한다
        $result = $this->ai->cardnewsRender($slides, $kind, $ratio, $setUuid);
        $images = $result['images'] ?? [];

        if (count($images) !== count($slides)) {
            throw new DomainException('이미지를 만들지 못했습니다. 잠시 후 다시 시도해 주세요.');
        }

        $rows = [];

        foreach (array_values($images) as $i => $image) {
            $bytes = base64_decode((string) ($image['png_base64'] ?? ''), true);

            // AI 서비스 응답이라도 PNG가 아니거나 너무 크면 저장하지 않는다.
            if ($bytes === false || ! str_starts_with($bytes, self::PNG_SIGNATURE) || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                throw new DomainException('이미지를 만들지 못했습니다. 잠시 후 다시 시도해 주세요.');
            }

            $rows[] = [
                'user_id' => $userId,
                'ai_generation_id' => $this->ownGeneration($generationId),
                'set_uuid' => $setUuid,
                'title' => Str::limit($title, 100, ''),
                'kind' => $kind,
                'slide_index' => $i,
                'ratio' => $ratio,
                'slide_title' => Str::limit($slides[$i]['title'], 40, ''),
                'data' => $bytes,
                'width' => (int) $image['width'],
                'height' => (int) $image['height'],
                'background_provider' => (string) ($result['background_provider'] ?? 'template'),
            ];
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                GeneratedImage::create($row);
            }
        });

        return $setUuid;
    }

    /** 화면에서 넘어온 id가 이 워크스페이스의 카드뉴스 초안 기록일 때만 연결한다. */
    private function ownGeneration(?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        return AiGeneration::query()->whereKey($id)->where('type', AiGeneration::TYPE_CARDNEWS)->value('id');
    }
}
