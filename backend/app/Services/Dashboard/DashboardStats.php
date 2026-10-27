<?php

namespace App\Services\Dashboard;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardStats
{
    /**
     * 기간 내 핵심 지표와 직전 동일 기간 대비 증감률.
     *
     * @return array<string, array{value: int|float, delta: float|null}>
     */
    public function summary(int $days): array
    {
        $to = now();
        $from = $to->copy()->subDays($days)->startOfDay();
        $prevFrom = $from->copy()->subDays($days);

        $current = $this->totals($from, $to);
        $previous = $this->totals($prevFrom, $from);

        $result = [];
        foreach ($current as $key => $value) {
            $result[$key] = ['value' => $value, 'delta' => $this->delta($value, $previous[$key])];
        }

        return $result;
    }

    /**
     * 일별 발송, 오픈, 클릭 추이 (빈 날짜는 0으로 채움).
     *
     * @return array{labels: list<string>, sent: list<int>, opens: list<int>, clicks: list<int>}
     */
    public function timeseries(int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $to = now()->endOfDay();

        $sent = $this->perDay($this->messagesQuery()->whereBetween('sent_at', [$from, $to]), 'sent_at');
        $opens = $this->perDay($this->eventsQuery(CampaignEvent::OPEN)->whereBetween('created_at', [$from, $to]), 'created_at');
        $clicks = $this->perDay($this->eventsQuery(CampaignEvent::CLICK)->whereBetween('created_at', [$from, $to]), 'created_at');

        $labels = [];
        $series = ['sent' => [], 'opens' => [], 'clicks' => []];

        for ($date = $from->copy(); $date <= $to; $date->addDay()) {
            $key = $date->toDateString();
            $labels[] = $date->format('m/d');
            $series['sent'][] = (int) ($sent[$key] ?? 0);
            $series['opens'][] = (int) ($opens[$key] ?? 0);
            $series['clicks'][] = (int) ($clicks[$key] ?? 0);
        }

        return ['labels' => $labels] + $series;
    }

    /**
     * 기간 내 발송 완료 캠페인별 성과 (최근 발송순).
     *
     * @return Collection<int, array{id: int, name: string, sent: int, open_rate: float, click_rate: float, sent_at: Carbon}>
     */
    public function campaignComparison(int $days, int $limit = 8): Collection
    {
        return Campaign::query()
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->subDays($days)->startOfDay())
            ->withCount([
                'messages as sent_count',
                'messages as opened_count' => fn ($q) => $q->whereNotNull('opened_at'),
                'events as click_count' => fn ($q) => $q->where('type', CampaignEvent::CLICK),
            ])
            ->orderByDesc('sent_at')
            ->limit($limit)
            ->get()
            ->map(fn (Campaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'sent' => $c->sent_count,
                'open_rate' => $c->sent_count ? round($c->opened_count / $c->sent_count * 100, 1) : 0.0,
                'click_rate' => $c->sent_count ? round($c->click_count / $c->sent_count * 100, 1) : 0.0,
                'sent_at' => $c->sent_at,
            ])
            ->reverse()
            ->values();
    }

    /** @return array{sent: int, open_rate: float, click_rate: float, conversions: int} */
    private function totals(Carbon $from, Carbon $to): array
    {
        $sent = $this->messagesQuery()->whereBetween('sent_at', [$from, $to])->count();
        $opened = $this->messagesQuery()->whereBetween('sent_at', [$from, $to])->whereNotNull('opened_at')->count();
        $clicks = $this->eventsQuery(CampaignEvent::CLICK)->whereBetween('created_at', [$from, $to])->count();
        $conversions = $this->eventsQuery(CampaignEvent::CONVERSION)->whereBetween('created_at', [$from, $to])->count();

        return [
            'sent' => $sent,
            'open_rate' => $sent ? round($opened / $sent * 100, 1) : 0.0,
            'click_rate' => $sent ? round($clicks / $sent * 100, 1) : 0.0,
            'conversions' => $conversions,
        ];
    }

    private function messagesQuery()
    {
        return CampaignMessage::query()
            ->where('status', 'sent')
            ->whereIn('campaign_id', Campaign::query()->select('id'));
    }

    private function eventsQuery(string $type)
    {
        return CampaignEvent::query()
            ->where('type', $type)
            ->whereIn('campaign_id', Campaign::query()->select('id'));
    }

    /** @return array<string, int> */
    private function perDay($query, string $column): array
    {
        return $query
            ->selectRaw("DATE({$column}) as day, COUNT(*) as total")
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();
    }

    private function delta(int|float $current, int|float $previous): ?float
    {
        if ($previous == 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}
