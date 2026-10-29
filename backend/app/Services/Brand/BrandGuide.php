<?php

namespace App\Services\Brand;

use App\Models\BrandChunk;
use Illuminate\Support\Str;

/** AI 카피 생성의 브랜드 지침(RAG)으로 쓸 문서 조각 후보. 현재 워크스페이스의 조각만 대상이다(모델의 워크스페이스 스코프가 적용된다). */
class BrandGuide
{
    public const MAX_CANDIDATES = 200;

    /**
     * 임베딩이 있는 조각을 최신순으로 최대 MAX_CANDIDATES개, AI 서비스의 `brand_chunks` 형식으로 돌려준다.
     *
     * @return list<array{id: int, document_title: string, content: string, vector: list<float>, embedding_provider: string}>
     */
    public function candidates(): array
    {
        return BrandChunk::query()
            ->whereNotNull('embedding')
            ->whereNotNull('embedding_provider')
            ->with('document:id,title')
            ->orderByDesc('id')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->filter(fn (BrandChunk $chunk) => is_array($chunk->embedding) && $chunk->document !== null)
            ->map(fn (BrandChunk $chunk) => [
                'id' => $chunk->id,
                'document_title' => Str::limit($chunk->document->title, 200, ''),
                'content' => Str::limit($chunk->content, 1000, ''),
                'vector' => $chunk->embedding,
                'embedding_provider' => $chunk->embedding_provider,
            ])
            ->values()
            ->all();
    }
}
