<?php

namespace App\Services\Ai;

/** AI 서비스가 돌려준 참고 자료를 화면에 보여 줄 형태로 바꾼다. 카피 패널과 카드뉴스 화면이 함께 쓴다. */
class ReferenceLabels
{
    /**
     * 우리가 보낸 후보에 있던 것만 남기고, 제목과 오픈율은 우리 쪽 값을 쓴다.
     * AI 서비스 응답이 다른 id를 끼워 넣거나 제목을 바꿔도 화면과 링크에 나오지 않게 하기 위해서다.
     *
     * @param  list<array<string, mixed>>  $references  AI 서비스 응답의 references
     * @param  list<array<string, mixed>>  $examples  보낸 캠페인 후보
     * @param  list<array<string, mixed>>  $brandChunks  보낸 브랜드 문서 조각 후보
     * @return list<array{kind: string, id: int, title: string, open_rate: float|null}>
     */
    public function resolve(array $references, array $examples, array $brandChunks): array
    {
        $campaigns = array_column($examples, null, 'id');
        $brand = array_column($brandChunks, null, 'id');
        $shown = [];

        foreach ($references as $reference) {
            $id = $reference['id'] ?? null;

            if (! is_int($id)) {
                continue;
            }

            if (($reference['kind'] ?? null) === 'campaign' && isset($campaigns[$id])) {
                $shown[] = ['kind' => 'campaign', 'id' => $id, 'title' => (string) $campaigns[$id]['subject'], 'open_rate' => (float) $campaigns[$id]['open_rate']];
            } elseif (($reference['kind'] ?? null) === 'brand' && isset($brand[$id])) {
                $shown[] = ['kind' => 'brand', 'id' => $id, 'title' => (string) $brand[$id]['document_title'], 'open_rate' => null];
            }
        }

        return $shown;
    }
}
