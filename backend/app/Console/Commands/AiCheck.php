<?php

namespace App\Console\Commands;

use App\Services\Ai\AiClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mktly:ai-check')]
#[Description('AI 서비스 연결과 내부 토큰 설정을 점검한다 (배포 로그 확인용)')]
class AiCheck extends Command
{
    public function handle(AiClient $ai): int
    {
        $url = config('services.ai.url');

        switch ($ai->status()) {
            case 'ok':
                $this->info("AI 서비스 연결 정상: {$url}");

                return self::SUCCESS;

            case 'unauthorized':
                $this->error("AI 서비스는 응답하지만 내부 토큰이 맞지 않습니다: {$url} (두 서비스의 AI_INTERNAL_TOKEN을 같은 값으로 맞춰 주세요)");

                return self::FAILURE;

            default:
                $this->error("AI 서비스에 연결할 수 없습니다: {$url} (AI_SERVICE_URL과 AI 서비스의 실행 상태를 확인해 주세요)");

                return self::FAILURE;
        }
    }
}
