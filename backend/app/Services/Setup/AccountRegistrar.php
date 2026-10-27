<?php

namespace App\Services\Setup;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * 공개 회원가입으로 계정과 새 워크스페이스를 만든다.
 *
 * 가입자는 자기 워크스페이스의 관리자가 되지만, 이메일 실제 발송은 꺼진 상태(체험 모드)로 시작한다.
 * 누구나 가입할 수 있는 서비스에서 외부 주소로 메일을 보내는 스팸 남용을 막기 위해서다.
 * 운영자가 `php artisan mktly:workspace-sending {id} on`으로 켜 준다.
 */
class AccountRegistrar
{
    public function register(string $name, string $email, string $password, string $workspaceName): User
    {
        return DB::transaction(function () use ($name, $email, $password, $workspaceName) {
            $user = User::create(['name' => trim($name), 'email' => $email, 'password' => $password]);

            $workspace = Workspace::create(['name' => trim($workspaceName) !== '' ? trim($workspaceName) : trim($name).'의 워크스페이스']);
            $workspace->forceFill(['sending_enabled' => false])->save();
            $workspace->users()->attach($user->id, ['role' => 'admin']);

            return $user;
        });
    }
}
