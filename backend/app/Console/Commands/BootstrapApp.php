<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Setup\AdminBootstrapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('mktly:bootstrap')]
#[Description('배포 시 실행: 사용자가 없고 MKTLY_ADMIN_* 환경변수가 있으면 첫 관리자를 만든다 (여러 번 실행해도 안전)')]
class BootstrapApp extends Command
{
    public function handle(AdminBootstrapper $bootstrapper): int
    {
        if (User::query()->exists()) {
            $this->line('이미 사용자가 있어 초기 계정을 만들지 않았습니다.');

            return self::SUCCESS;
        }

        $admin = config('mktly.admin');

        if (! $admin['email'] || ! $admin['password']) {
            $this->warn('MKTLY_ADMIN_EMAIL, MKTLY_ADMIN_PASSWORD가 설정되지 않아 초기 계정을 만들지 않았습니다.');

            return self::SUCCESS;
        }

        try {
            $user = $bootstrapper->create($admin['name'], $admin['email'], $admin['password'], $admin['workspace']);
        } catch (InvalidArgumentException $e) {
            // 배포가 실패하지 않도록 오류만 알리고 정상 종료한다. 비밀번호는 출력하지 않는다.
            $this->error('초기 관리자 생성 실패: '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->info("초기 관리자 계정을 만들었습니다: {$user->email}");

        return self::SUCCESS;
    }
}
