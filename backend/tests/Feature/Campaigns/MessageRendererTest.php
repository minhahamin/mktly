<?php

use App\Models\Contact;
use App\Services\Campaigns\MessageRenderer;

beforeEach(function () {
    $this->renderer = new MessageRenderer;
    $this->contact = new Contact(['name' => '홍길동', 'email' => 'hong@example.test']);
});

it('본문과 제목의 변수를 치환한다', function () {
    expect($this->renderer->subject('{{ name }}님 안녕하세요', $this->contact))->toBe('홍길동님 안녕하세요');

    $html = $this->renderer->html("안녕 {{name}} ({{email}})\n두번째 줄", $this->contact);

    expect($html)->toContain('안녕 홍길동 (hong@example.test)')->toContain('<br>');
});

it('고객 이름과 본문의 HTML을 이스케이프한다', function () {
    $evil = new Contact(['name' => '<script>alert(1)</script>', 'email' => 'x@example.test']);

    $html = $this->renderer->html('{{name}} <b>굵게</b> <img src=x onerror=alert(1)>', $evil);

    expect($html)->not->toContain('<script>')->not->toContain('<b>')->not->toContain('<img src=x')
        ->and($html)->toContain('&lt;script&gt;');
});

it('본문의 고유 URL을 추출하고 끝의 문장부호는 제외한다', function () {
    $urls = $this->renderer->extractUrls('보세요 https://a.test/shop. 그리고 (https://b.test/x?y=1&z=2) 그리고 https://a.test/shop');

    expect($urls)->toBe(['https://a.test/shop', 'https://b.test/x?y=1&z=2']);
});

it('추적 토큰이 있으면 링크를 추적 URL로 바꾸고 오픈 픽셀을 붙인다', function () {
    $links = ['https://a.test/shop' => str_repeat('a', 40)];
    $token = str_repeat('m', 40);

    $html = $this->renderer->html('구매하기 https://a.test/shop!', $this->contact, $links, $token);

    expect($html)
        ->toContain('/t/c/'.str_repeat('a', 40).'?m='.$token)
        ->toContain('>https://a.test/shop</a>!')
        ->toContain('/t/o/'.$token.'.gif');
});

it('추적 토큰이 없으면(미리보기) 원본 URL을 그대로 쓰고 픽셀을 붙이지 않는다', function () {
    $html = $this->renderer->html('https://a.test/shop', $this->contact, ['https://a.test/shop' => str_repeat('a', 40)]);

    expect($html)->toContain('href="https://a.test/shop"')->not->toContain('/t/o/');
});

it('추적 목록에 없는 URL은 추적하지 않는다', function () {
    $html = $this->renderer->html('https://other.test', $this->contact, [], str_repeat('m', 40));

    expect($html)->toContain('href="https://other.test"');
});
