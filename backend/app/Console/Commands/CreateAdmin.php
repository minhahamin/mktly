<?php

namespace App\Console\Commands;

use App\Services\Setup\AdminBootstrapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('mktly:create-admin {--name=관리자 : 이름} {--email= : 이메일} {--workspace=내 워크스페이스 : 워크스페이스 이름}')]
#[Description('관리자 계정과 워크스페이스를 만든다 (비밀번호는 MKTLY_ADMIN_PASSWORD 환경변수 또는 입력으로 받는다)')]
class CreateAdmin extends Command
{
    public function handle(AdminBootstrapper $bootstrapper): int
    {
        $email = $this->option('email') ?: ($this->input->isInteractive() ? $this->ask('이메일') : null);
        $password = getenv('MKTLY_ADMIN_PASSWORD') ?: ($this->input->isInteractive() ? $this->secret('비밀번호 ('.AdminBootstrapper::MIN_PASSWORD_LENGTH.'자 이상)') : null);

        if (! $email || ! $password) {
            $this->error('이메일과 비밀번호가 필요합니다. --email 옵션과 MKTLY_ADMIN_PASSWORD 환경변수를 지정해 주세요.');

            return self::FAILURE;
        }

        try {
            $user = $bootstrapper->create((string) $this->option('name'), $email, $password, (string) $this->option('workspace'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("관리자 계정을 만들었습니다: {$user->email}");

        return self::SUCCESS;
    }
}
