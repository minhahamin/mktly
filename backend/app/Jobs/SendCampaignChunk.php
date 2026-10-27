<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\TrackedLink;
use App\Services\Campaigns\CampaignSender;
use App\Services\Campaigns\MessageRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/** 캠페인 메시지 한 묶음(최대 500건)을 발송한다. 이미 처리된 메시지는 건너뛰므로 재시도해도 안전하다. */
class SendCampaignChunk implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 15;

    /** @param  list<int>  $messageIds */
    public function __construct(public int $campaignId, public array $messageIds) {}

    public function handle(MessageRenderer $renderer, CampaignSender $sender): void
    {
        $campaign = Campaign::withoutGlobalScopes()->find($this->campaignId);

        if ($campaign === null || $campaign->status !== 'sending') {
            return;
        }

        $links = TrackedLink::where('campaign_id', $campaign->id)->pluck('token', 'target_url')->all();

        $messages = CampaignMessage::with('contact')
            ->where('campaign_id', $campaign->id)
            ->whereIn('id', $this->messageIds)
            ->where('status', 'pending')
            ->get();

        foreach ($messages as $message) {
            try {
                $this->deliver($campaign, $message, $renderer, $links);
                $message->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (Throwable $e) {
                report($e);
                $message->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 250, '')]);
            }
        }

        $sender->finalize($campaign->id);
    }

    /** @param  array<string, string>  $links */
    private function deliver(Campaign $campaign, CampaignMessage $message, MessageRenderer $renderer, array $links): void
    {
        // SMS/푸시는 모의 채널이다. 실제 전송 없이 발송 완료로 기록한다.
        if ($campaign->channel !== 'email') {
            return;
        }

        $contact = $message->contact;
        $html = $renderer->html($campaign->body, $contact, $links, $message->token);
        $subject = $renderer->subject($campaign->subject, $contact);

        Mail::html($html, function ($mail) use ($contact, $subject) {
            $mail->to($contact->email, $contact->name)->subject($subject);
        });
    }
}
