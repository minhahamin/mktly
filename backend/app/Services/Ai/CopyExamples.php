<?php

namespace App\Services\Ai;

use App\Models\Campaign;
use Illuminate\Support\Str;

/**
 * AI 카피 생성의 참고 자료(RAG)로 쓸 과거 캠페인을 고른다.
 * 현재 워크스페이스의 발송 완료 캠페인만 대상이다(Campaign의 워크스페이스 스코프가 적용된다).
 */
class CopyExamples
{
    /** 이보다 적은 수신자에게 보낸 캠페인은 오픈율이 우연에 좌우되므로 제외한다. */
    public const MIN_RECIPIENTS = 30;

    public const MAX_CANDIDATES = 100;

    /** 최근 발송한 캠페인 중 이 개수까지만 살펴본다. */
    private const SCAN_LIMIT = 300;

    /** 임베딩을 만들 때 쓰는 텍스트: 제목과 본문. AI 서비스의 텍스트 길이 제한(4000자) 안에 둔다. */
    public static function textFor(Campaign $campaign): string
    {
        return Str::limit(trim($campaign->subject."\n".$campaign->body), 3500, '');
    }

    /**
     * 임베딩이 있고 오픈율이 워크스페이스 중앙값 이상인 캠페인을 AI 서비스의 `examples` 형식으로 돌려준다.
     * 클릭률은 클릭 이벤트 수를 발송 수로 나눈 값이다(같은 사람의 중복 클릭 포함, 1로 제한).
     *
     * @return list<array{id: int, subject: string, body: string, open_rate: float, click_rate: float, vector: list<float>, embedding_provider: string}>
     */
    public function candidates(): array
    {
        $campaigns = Campaign::query()
            ->where('status', 'sent')
            ->whereNotNull('embedding')
            ->whereNotNull('embedding_provider')
            ->withCount([
                'messages as sent_count' => fn ($q) => $q->where('status', 'sent'),
                'messages as opened_count' => fn ($q) => $q->where('status', 'sent')->whereNotNull('opened_at'),
                'events as click_count' => fn ($q) => $q->where('type', 'click'),
            ])
            ->orderByDesc('sent_at')
            ->limit(self::SCAN_LIMIT)
            ->get()
            ->filter(fn (Campaign $c) => $c->sent_count >= self::MIN_RECIPIENTS && is_array($c->embedding))
            ->values();

        if ($campaigns->isEmpty()) {
            return [];
        }

        $median = $this->median($campaigns->map(fn (Campaign $c) => $c->opened_count / $c->sent_count)->all());

        return $campaigns
            ->filter(fn (Campaign $c) => $c->opened_count / $c->sent_count >= $median)
            ->take(self::MAX_CANDIDATES)
            ->map(fn (Campaign $c) => [
                'id' => $c->id,
                'subject' => Str::limit($c->subject, 300, ''),
                'body' => Str::limit($c->body, 2000, ''),
                'open_rate' => round($c->opened_count / $c->sent_count, 4),
                'click_rate' => min(1.0, round($c->click_count / $c->sent_count, 4)),
                'vector' => $c->embedding,
                'embedding_provider' => $c->embedding_provider,
            ])
            ->values()
            ->all();
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
