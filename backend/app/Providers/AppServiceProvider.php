<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
    }
}
