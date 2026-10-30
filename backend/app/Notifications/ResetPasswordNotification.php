<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/** 비밀번호 재설정 링크 메일. 프레임워크 기본 메일(영문)을 한국어 안내로 바꾼다. */
class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url('/reset-password/'.$this->token).'?email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('[Mktly] 비밀번호 재설정 안내')
            ->view('mail.reset-password', [
                'name' => $notifiable->name,
                'url' => $url,
                'minutes' => (int) config('auth.passwords.users.expire'),
            ]);
    }
}
