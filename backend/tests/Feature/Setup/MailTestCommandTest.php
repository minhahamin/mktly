<?php

use Illuminate\Support\Facades\Mail;

it('설정된 메일러로 테스트 메일을 한 통 보낸다', function () {
    $transport = Mail::mailer('array')->getSymfonyTransport();
    $before = count($transport->messages());

    $this->artisan('mktly:mail-test', ['to' => 'me@example.test'])
        ->expectsOutputToContain('테스트 메일을 보냈습니다: me@example.test')->assertSuccessful();

    $sent = collect($transport->messages())->slice($before)->first()->getOriginalMessage();

    expect(count($transport->messages()) - $before)->toBe(1)
        ->and($sent->getSubject())->toBe('[Mktly] 메일 설정 테스트')
        ->and($sent->getTo()[0]->getAddress())->toBe('me@example.test');
});

it('실제로 전송하지 않는 메일러라면 경고한다', function () {
    $this->artisan('mktly:mail-test', ['to' => 'me@example.test'])->expectsOutputToContain('실제로 전송하지 않습니다');
});

it('잘못된 이메일 주소는 거부한다', function () {
    $this->artisan('mktly:mail-test', ['to' => 'not-an-email'])->expectsOutputToContain('올바른 이메일')->assertFailed();
});

it('전송에 실패하면 원인과 점검 안내를 보여주고 인증 정보는 출력하지 않는다', function () {
    config(['mail.mailers.smtp.password' => 'super-secret-smtp-key']);
    Mail::shouldReceive('html')->andThrow(new RuntimeException('Authentication failed: 535 5.7.8'));

    $this->artisan('mktly:mail-test', ['to' => 'me@example.test'])
        ->expectsOutputToContain('발송에 실패했습니다: Authentication failed')
        ->expectsOutputToContain('SMTP 키')
        ->doesntExpectOutputToContain('super-secret-smtp-key')
        ->assertFailed();
});
