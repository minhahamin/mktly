<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

#[Signature('mktly:mail-test {to : 테스트 메일을 받을 이메일}')]
#[Description('현재 메일 설정(SMTP 등)으로 테스트 메일을 한 통 보낸다. 배포 후 Brevo 설정 확인용')]
class MailTest extends Command
{
    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (Validator::make(['to' => $to], ['to' => 'required|email:rfc'])->fails()) {
            $this->error('올바른 이메일 주소를 입력해 주세요.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $smtp = $mailer === 'smtp' ? ' ('.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').')' : '';
        $from = config('mail.from.address');

        $this->line("메일러: {$mailer}{$smtp}, 보내는 사람: {$from}");

        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn('이 메일러는 실제로 전송하지 않습니다. 배포 환경에서는 MAIL_MAILER=brevo(또는 smtp)로 설정해 주세요.');
        }

        try {
            Mail::html('<p>Mktly 메일 설정 테스트입니다. 이 메일이 보인다면 발송 설정이 올바릅니다.</p>', function ($message) use ($to) {
                $message->to($to)->subject('[Mktly] 메일 설정 테스트');
            });
        } catch (Throwable $e) {
            // 인증 정보는 메시지에 포함되지 않지만, 원인 파악에 필요한 한 줄만 보여준다.
            $this->error('발송에 실패했습니다: '.strtok($e->getMessage(), "\n"));
            $this->line($mailer === 'brevo'
                ? 'BREVO_API_KEY(SMTP 키가 아닌 API 키)와 보내는 사람 주소의 인증 여부를 확인해 주세요.'
                : 'SMTP 로그인과 SMTP 키, 보내는 사람 주소의 인증 여부를 확인해 주세요. 호스팅이 SMTP 포트를 막으면 MAIL_MAILER=brevo를 써 주세요.');

            return self::FAILURE;
        }

        $this->info("테스트 메일을 보냈습니다: {$to}");

        return self::SUCCESS;
    }
}
