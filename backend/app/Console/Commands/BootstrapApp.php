<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Setup\AdminBootstrapper;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('mktly:bootstrap')]
#[Description('배포 시 실행: 첫 관리자(MKTLY_ADMIN_*)와 공개 데모 데이터(MKTLY_SEED_DEMO)를 필요할 때만 만든다 (여러 번 실행해도 안전)')]
class BootstrapApp extends Command
{
    public function handle(AdminBootstrapper $bootstrapper, DemoSeeder $demo): int
    {
        $this->createFirstAdmin($bootstrapper);
        $this->createDemoData($demo);

        return self::SUCCESS;
    }

    private function createFirstAdmin(AdminBootstrapper $bootstrapper): void
    {
        if (User::query()->exists()) {
            $this->line('이미 사용자가 있어 초기 계정을 만들지 않았습니다.');

            return;
        }

        $admin = config('mktly.admin');

        if (! $admin['email'] || ! $admin['password']) {
            $this->warn('MKTLY_ADMIN_EMAIL, MKTLY_ADMIN_PASSWORD가 설정되지 않아 초기 계정을 만들지 않았습니다.');

            return;
        }

        try {
            $user = $bootstrapper->create($admin['name'], $admin['email'], $admin['password'], $admin['workspace']);
        } catch (InvalidArgumentException $e) {
            // 배포가 실패하지 않도록 오류만 알리고 계속한다. 비밀번호는 출력하지 않는다.
            $this->error('초기 관리자 생성 실패: '.$e->getMessage());

            return;
        }

        $this->info("초기 관리자 계정을 만들었습니다: {$user->email}");
    }

    /** MKTLY_SEED_DEMO=true이고 데모 계정이 아직 없을 때만 별도 워크스페이스에 데모 데이터를 만든다. */
    private function createDemoData(DemoSeeder $demo): void
    {
        if (! config('mktly.demo.seed')) {
            return;
        }

        $email = (string) config('mktly.demo.email');
        $password = (string) config('mktly.demo.password');
        $role = (string) config('mktly.demo.role');

        if (strlen($password) < 8 || ! in_array($role, ['viewer', 'marketer', 'admin'], true)) {
            $this->error('데모 데이터 생성 실패: MKTLY_DEMO_PASSWORD(8자 이상)와 MKTLY_DEMO_ROLE을 확인해 주세요.');

            return;
        }

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            $this->markAsDemo($existing);
            $this->line('데모 계정이 이미 있어 데모 데이터를 만들지 않았습니다.');

            return;
        }

        $demo->seed($email, $password, $role);
        $this->info("데모 데이터를 만들었습니다: {$email} (역할: {$role})");
    }

    /** 데모 표시가 도입되기 전에 만든 데모 계정을 보정한다(데모 표시, 데모 워크스페이스의 실제 발송 끄기). */
    private function markAsDemo(User $user): void
    {
        if (! $user->is_demo) {
            $user->forceFill(['is_demo' => true])->save();
            $this->line('기존 데모 계정에 데모 표시를 추가했습니다.');
        }

        Workspace::whereIn('id', $user->workspaces()->pluck('workspaces.id'))->update(['sending_enabled' => false]);
    }
}
