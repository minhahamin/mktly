<?php

use App\Livewire\Auth\ForgotPassword;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Brevo API 대신 응답하는 가짜 HTTP 클라이언트를 끼우고, 보낸 요청을 $requests에 모은다. */
function brevoFake(array &$requests, int $status = 201): void
{
    config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'test-api-key', 'mail.from' => ['address' => 'noreply@mktly.test', 'name' => 'Mktly']]);

    app()->instance(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, $status) {
        $requests[] = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'], 'body' => json_decode($options['body'], true)];

        return new MockResponse($status === 201 ? '{"messageId":"<1@brevo>"}' : '{"message":"Key not found"}', ['http_code' => $status]);
    }));

    Mail::purge('brevo');
}

it('MAIL_MAILER=brevo이면 SMTP가 아니라 Brevo HTTP API로 보낸다', function () {
    $requests = [];
    brevoFake($requests);

    Mail::html('<p>본문입니다</p>', fn ($message) => $message->to('owner@shop.test')->subject('테스트 제목'));

    expect($requests)->toHaveCount(1)
        ->and($requests[0]['method'])->toBe('POST')
        ->and($requests[0]['url'])->toBe('https://api.brevo.com/v3/smtp/email')
        ->and($requests[0]['headers']['api-key'][0])->toBe('api-key: test-api-key')
        ->and($requests[0]['body']['subject'])->toBe('테스트 제목')
        ->and($requests[0]['body']['to'][0]['email'])->toBe('owner@shop.test')
        ->and($requests[0]['body']['sender']['email'])->toBe('noreply@mktly.test')
        ->and($requests[0]['body']['htmlContent'])->toContain('본문입니다');
});

it('비밀번호 재설정 메일도 Brevo API로 나간다', function () {
    $requests = [];
    brevoFake($requests);
    RateLimiter::clear('forgot-password|127.0.0.1');
    User::factory()->create(['email' => 'owner@shop.test']);

    Livewire::test(ForgotPassword::class)->set('email', 'owner@shop.test')->call('send')->assertSet('sent', true);

    expect($requests)->toHaveCount(1)
        ->and($requests[0]['body']['subject'])->toBe('[Mktly] 비밀번호 재설정 안내')
        ->and($requests[0]['body']['htmlContent'])->toContain('/reset-password/');
});

it('Brevo가 거부해도(잘못된 키 등) 비밀번호 찾기 화면은 같은 안내를 보여 준다', function () {
    $requests = [];
    brevoFake($requests, 401);
    RateLimiter::clear('forgot-password|127.0.0.1');
    User::factory()->create(['email' => 'owner@shop.test']);

    Livewire::test(ForgotPassword::class)->set('email', 'owner@shop.test')->call('send')
        ->assertHasNoErrors()->assertSet('sent', true);

    expect($requests)->toHaveCount(1);
});
