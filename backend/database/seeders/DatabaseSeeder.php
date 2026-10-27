<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 데모 계정의 비밀번호가 저장소에 공개되어 있으므로 로컬에서만 자동으로 시드한다.
        // 배포 환경의 데모 데이터는 `php artisan mktly:demo`로 명시적으로 만든다.
        if (! app()->isLocal()) {
            $this->command?->warn('로컬 환경이 아니어서 데모 데이터를 건너뜁니다. 필요하면 mktly:demo를 사용하세요.');

            return;
        }

        $this->call(DemoSeeder::class);
    }
}
