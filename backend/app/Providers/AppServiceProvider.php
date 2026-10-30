<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 메일 속 추적 링크와 에셋 URL이 항상 https로 만들어지도록 한다.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // MAIL_MAILER=brevo: SMTP 대신 Brevo HTTP API로 보낸다(SMTP 포트가 막힌 호스팅용). 테스트는 HTTP 클라이언트를 바꿔 끼운다.
        Mail::extend('brevo', function (array $config) {
            $client = $this->app->bound(HttpClientInterface::class)
                ? $this->app->make(HttpClientInterface::class)
                : HttpClient::create(['timeout' => $config['timeout'] ?? 10]);

            return (new BrevoTransportFactory(null, $client))->create(new Dsn('brevo+api', 'default', (string) ($config['key'] ?? '')));
        });
    }
}
