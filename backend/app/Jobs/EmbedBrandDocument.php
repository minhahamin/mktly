<?php

namespace App\Jobs;

use App\Models\BrandChunk;
use App\Services\Ai\AiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 브랜드 문서 조각의 임베딩을 만든다. 기본은 임베딩이 없는 조각만 처리하므로 다시 실행해도 안전하다.
 * AI 서비스 장애 시 간격을 두고 재시도한다.
 */
class EmbedBrandDocument implements ShouldQueue
{
    use Queueable;

    /** AI 서비스가 한 번에 받는 텍스트 수의 상한 */
    private const BATCH = 64;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $documentId, public bool $replace = false) {}

    public function handle(AiClient $ai): void
    {
        BrandChunk::withoutGlobalScopes()
            ->where('brand_document_id', $this->documentId)
            ->when(! $this->replace, fn ($query) => $query->whereNull('embedding'))
            ->orderBy('position')
            ->get()
            ->chunk(self::BATCH)
            ->each(function ($batch) use ($ai) {
                $result = $ai->embed($batch->pluck('content')->all());

                foreach ($batch->values() as $i => $chunk) {
                    $chunk->forceFill([
                        'embedding' => array_map(fn ($value) => round((float) $value, 5), $result['vectors'][$i]),
                        'embedding_provider' => $result['provider'],
                    ])->save();
                }
            });
    }
}
