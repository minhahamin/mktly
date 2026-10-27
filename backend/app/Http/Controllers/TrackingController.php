<?php

namespace App\Http\Controllers;

use App\Models\CampaignEvent;
use App\Models\CampaignMessage;
use App\Models\TrackedLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** 인증 없이 호출되는 공개 추적 엔드포인트(오픈 픽셀, 클릭 리디렉션). */
class TrackingController extends Controller
{
    /** 투명 1x1 GIF */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $token): Response
    {
        $message = CampaignMessage::where('token', $token)->first();

        // 토큰이 없어도 항상 같은 응답을 돌려줘 유효한 토큰을 추측할 수 없게 한다.
        if ($message !== null) {
            $this->recordOpen($message);
        }

        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    public function click(Request $request, string $token): RedirectResponse
    {
        $link = TrackedLink::where('token', $token)->firstOrFail();

        $messageToken = $request->query('m');
        $message = is_string($messageToken)
            ? CampaignMessage::where('token', $messageToken)->where('campaign_id', $link->campaign_id)->first()
            : null;

        if ($message !== null) {
            // 클릭은 오픈을 의미하므로 아직 오픈 기록이 없다면 함께 남긴다.
            $this->recordOpen($message);

            CampaignEvent::create([
                'campaign_id' => $message->campaign_id,
                'contact_id' => $message->contact_id,
                'type' => CampaignEvent::CLICK,
                'tracked_link_id' => $link->id,
                'created_at' => now(),
            ]);
        }

        // 이동 대상은 발송 시점에 저장된 URL뿐이다(요청 파라미터로 목적지를 바꿀 수 없다).
        return redirect()->away($link->target_url);
    }

    private function recordOpen(CampaignMessage $message): void
    {
        // 최초 1회만 기록한다. 조건부 업데이트라 동시 요청에도 이벤트가 중복되지 않는다.
        $updated = CampaignMessage::whereKey($message->id)->whereNull('opened_at')->update(['opened_at' => now()]);

        if ($updated === 1) {
            CampaignEvent::create([
                'campaign_id' => $message->campaign_id,
                'contact_id' => $message->contact_id,
                'type' => CampaignEvent::OPEN,
                'created_at' => now(),
            ]);
        }
    }
}
