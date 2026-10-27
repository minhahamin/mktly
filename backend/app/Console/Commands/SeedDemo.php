<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mktly:demo {--role=viewer : 데모 계정 역할(viewer, marketer, admin)} {--force : 운영 환경에서 실행 확인}')]
#[Description('별도 데모 워크스페이스에 샘플 데이터와 데모 계정을 만든다 (비밀번호는 MKTLY_DEMO_PASSWORD)')]
class SeedDemo extends Command
{
    public function handle(DemoSeeder $seeder): int
    {
        $role = (string) $this->option('role');

        if (! in_array($role, ['viewer', 'marketer', 'admin'], true)) {
            $this->error('역할은 viewer, marketer, admin 중 하나여야 합니다.');

            return self::FAILURE;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('운영 환경입니다. 데모 데이터를 만들려면 --force 옵션을 함께 지정해 주세요.');

            return self::FAILURE;
        }

        $email = (string) config('mktly.demo.email');
        $password = config('mktly.demo.password');

        if (! $password || strlen($password) < 8) {
            $this->error('MKTLY_DEMO_PASSWORD(8자 이상)를 설정해 주세요.');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("데모 계정({$email})이 이미 있습니다. 새로 만들려면 먼저 삭제해 주세요.");

            return self::FAILURE;
        }

        $workspace = $seeder->seed($email, $password, $role);

        $this->info("데모 워크스페이스를 만들었습니다 (#{$workspace->id}). 데모 계정: {$email} / 역할: {$role}");
        $this->line('데모 계정은 공개용이므로 읽기 전용(viewer)을 권장합니다. 마케터 이상이면 방문자가 메일 발송과 AI 호출을 할 수 있습니다.');

        return self::SUCCESS;
    }
}
