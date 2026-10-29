<?php

namespace App\Services\Brand;

use App\Jobs\EmbedBrandDocument;
use App\Models\BrandDocument;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** 브랜드 문서 등록. 문서를 조각으로 나눠 저장하고 임베딩 작업을 큐에 올린다. 현재 사용자의 워크스페이스에 만든다. */
class BrandDocuments
{
    public const MAX_DOCUMENTS = 20;

    public const MAX_BYTES = 51200;

    public function __construct(private readonly ChunkSplitter $splitter) {}

    /** @throws DomainException 문서 수 한도를 넘었거나 내용을 읽을 수 없는 경우 */
    public function create(string $title, string $content, ?int $userId = null): BrandDocument
    {
        if (BrandDocument::query()->count() >= self::MAX_DOCUMENTS) {
            throw new DomainException('브랜드 문서는 워크스페이스당 최대 '.self::MAX_DOCUMENTS.'개까지 등록할 수 있습니다. 쓰지 않는 문서를 삭제해 주세요.');
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new DomainException('UTF-8 텍스트 파일만 사용할 수 있습니다.');
        }

        $chunks = $this->splitter->split($content);

        if ($chunks === []) {
            throw new DomainException('내용이 비어 있습니다.');
        }

        $document = DB::transaction(function () use ($title, $content, $userId, $chunks) {
            $document = BrandDocument::create(['title' => $title, 'content' => $content, 'created_by' => $userId]);

            foreach ($chunks as $position => $chunk) {
                $document->chunks()->create(['workspace_id' => $document->workspace_id, 'position' => $position, 'content' => $chunk]);
            }

            return $document;
        });

        $this->queueEmbedding($document->id);

        return $document;
    }

    /** 실패해도 등록은 유지한다. 임베딩이 없는 조각은 `mktly:embeddings:rebuild`가 나중에 채운다. */
    private function queueEmbedding(int $documentId): void
    {
        try {
            EmbedBrandDocument::dispatch($documentId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
