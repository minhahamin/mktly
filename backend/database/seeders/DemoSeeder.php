<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Segment;
use App\Models\User;
use App\Models\Workspace;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    private const CAMPAIGNS = [
        ['가을 시즌 프로모션', '이번 가을, 놓치면 아쉬운 단독 혜택', 27],
        ['신규 가입 환영 메일', '{{name}}님, 환영합니다', 23],
        ['휴면 고객 재활성화', '{{name}}님, 다시 만나서 반가워요', 19],
        ['VIP 감사 쿠폰', '단골 고객님께만 드리는 감사 쿠폰', 14],
        ['주말 한정 세일', '이번 주말 48시간 한정 세일', 9],
        ['신제품 출시 안내', '새로운 라인업을 가장 먼저 만나보세요', 4],
    ];

    public function run(): void
    {
        mt_srand(42);

        $workspace = Workspace::create(['name' => 'Mktly 데모 스토어']);
        $user = User::create([
            'name' => '데모 마케터',
            'email' => config('mktly.demo.email'),
            'password' => config('mktly.demo.password'),
        ]);
        $workspace->users()->attach($user->id, ['role' => 'admin']);

        $contactIds = $this->seedContacts($workspace->id);
        $this->seedSegments($workspace->id);
        $this->seedCampaigns($workspace->id, $contactIds);
    }

    /** @return list<int> */
    private function seedContacts(int $workspaceId): array
    {
        $surnames = ['김', '이', '박', '최', '정', '강', '조', '윤', '장', '임'];
        $given = ['민준', '서연', '도윤', '하은', '시우', '지우', '예준', '수아', '주원', '지안', '현우', '다은', '지호', '채원', '유진'];
        $tags = ['뉴스레터', 'VIP', '신규', '모바일', '이벤트참여'];
        $now = now();
        $rows = [];

        for ($i = 1; $i <= 1000; $i++) {
            $joined = $now->copy()->subDays(mt_rand(10, 720));
            $purchased = mt_rand(1, 100) <= 85 ? $now->copy()->subDays(mt_rand(1, 365)) : null;

            $rows[] = [
                'workspace_id' => $workspaceId,
                'email' => 'customer'.$i.'@example.test',
                'name' => $surnames[array_rand($surnames)].$given[array_rand($given)],
                'tags' => json_encode(array_slice($tags, mt_rand(0, 4), mt_rand(1, 2)), JSON_UNESCAPED_UNICODE),
                'joined_at' => $joined->toDateString(),
                'last_purchase_at' => $purchased?->toDateString(),
                'total_spent' => $purchased ? mt_rand(1, 60) * 10000 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('contacts')->insert($chunk);
        }

        return DB::table('contacts')->where('workspace_id', $workspaceId)->pluck('id')->all();
    }

    private function seedSegments(int $workspaceId): void
    {
        $defs = [
            ['VIP 고객', [['field' => 'total_spent', 'op' => '>=', 'value' => 300000]]],
            ['최근 가입 (30일)', [['field' => 'joined_at', 'op' => 'within_days', 'value' => 30]]],
            ['90일 이상 미구매', [['field' => 'last_purchase_at', 'op' => 'older_than_days', 'value' => 90]]],
        ];

        foreach ($defs as [$name, $rules]) {
            Segment::withoutGlobalScopes()->create(['workspace_id' => $workspaceId, 'name' => $name, 'rules' => $rules]);
        }
    }

    /** @param list<int> $contactIds */
    private function seedCampaigns(int $workspaceId, array $contactIds): void
    {
        foreach (self::CAMPAIGNS as $index => [$name, $subject, $daysAgo]) {
            $sentAt = now()->subDays($daysAgo)->setTime(10, 0);
            $campaign = Campaign::withoutGlobalScopes()->create([
                'workspace_id' => $workspaceId,
                'name' => $name,
                'channel' => 'email',
                'subject' => $subject,
                'body' => "안녕하세요 {{name}}님,\n\n{$name} 소식을 전해드립니다.",
                'status' => 'sent',
                'sent_at' => $sentAt,
            ]);

            $linkIds = [];
            foreach (['https://example.test/shop', 'https://example.test/event'] as $url) {
                $linkIds[] = DB::table('tracked_links')->insertGetId([
                    'campaign_id' => $campaign->id,
                    'token' => Str::random(40),
                    'target_url' => $url,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->seedMessagesAndEvents($campaign->id, $sentAt, $contactIds, $linkIds, 600 + $index * 50);
        }

        Campaign::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'name' => '연말 감사 이벤트',
            'channel' => 'email',
            'subject' => '한 해 동안 감사했습니다',
            'body' => '안녕하세요 {{name}}님, 연말 이벤트를 준비했어요.',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(3)->setTime(9, 0),
        ]);

        Campaign::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceId,
            'name' => '블랙프라이데이 사전 안내',
            'channel' => 'email',
            'subject' => '블랙프라이데이 미리보기',
            'body' => '안녕하세요 {{name}}님.',
            'status' => 'draft',
        ]);
    }

    /**
     * @param  list<int>  $contactIds
     * @param  list<int>  $linkIds
     */
    private function seedMessagesAndEvents(int $campaignId, DateTimeInterface $sentAt, array $contactIds, array $linkIds, int $recipients): void
    {
        $keys = (array) array_rand($contactIds, min($recipients, count($contactIds)));
        $messages = [];
        $events = [];
        $baseTime = Carbon::instance($sentAt);

        foreach ($keys as $key) {
            $contactId = $contactIds[$key];
            $opened = mt_rand(1, 100) <= 42;
            $openedAt = $opened ? $baseTime->copy()->addMinutes(mt_rand(3, 3000)) : null;

            $messages[] = [
                'campaign_id' => $campaignId,
                'contact_id' => $contactId,
                'status' => 'sent',
                'sent_at' => $baseTime,
                'opened_at' => $openedAt,
                'created_at' => $baseTime,
                'updated_at' => $baseTime,
            ];

            if (! $opened) {
                continue;
            }

            $events[] = $this->event($campaignId, $contactId, CampaignEvent::OPEN, null, $openedAt);

            if (mt_rand(1, 100) <= 24) {
                $clickedAt = $openedAt->copy()->addMinutes(mt_rand(1, 120));
                $events[] = $this->event($campaignId, $contactId, CampaignEvent::CLICK, $linkIds[array_rand($linkIds)], $clickedAt);

                if (mt_rand(1, 100) <= 25) {
                    $events[] = $this->event($campaignId, $contactId, CampaignEvent::CONVERSION, null, $clickedAt->copy()->addMinutes(mt_rand(5, 600)));
                }
            }
        }

        foreach (array_chunk($messages, 500) as $chunk) {
            DB::table('campaign_messages')->insert($chunk);
        }

        foreach (array_chunk($events, 500) as $chunk) {
            DB::table('campaign_events')->insert($chunk);
        }
    }

    /** @return array<string, mixed> */
    private function event(int $campaignId, int $contactId, string $type, ?int $linkId, DateTimeInterface $at): array
    {
        return [
            'campaign_id' => $campaignId,
            'contact_id' => $contactId,
            'type' => $type,
            'tracked_link_id' => $linkId,
            'created_at' => $at,
        ];
    }
}
