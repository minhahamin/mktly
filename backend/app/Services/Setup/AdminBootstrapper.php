<?php

namespace App\Services\Setup;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** 빈 DB에 첫 관리자 계정과 워크스페이스를 만든다. 회원가입 화면이 없으므로 배포 직후 이 경로로 시작한다. */
class AdminBootstrapper
{
    public const MIN_PASSWORD_LENGTH = 12;

    /** @throws InvalidArgumentException 입력이 올바르지 않을 때(메시지는 사용자에게 그대로 보여줄 수 있다) */
    public function create(string $name, string $email, string $password, string $workspaceName): User
    {
        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'workspace' => $workspaceName],
            [
                'name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:'.self::MIN_PASSWORD_LENGTH],
                'workspace' => ['required', 'string', 'max:100'],
            ],
            [
                'email.unique' => '이미 사용 중인 이메일입니다.',
                'email.email' => '올바른 이메일 형식이 아닙니다.',
                'password.min' => '비밀번호는 '.self::MIN_PASSWORD_LENGTH.'자 이상이어야 합니다.',
            ],
        );

        if ($validator->fails()) {
            throw new InvalidArgumentException($validator->errors()->first());
        }

        return DB::transaction(function () use ($name, $email, $password, $workspaceName) {
            $user = User::create(['name' => trim($name), 'email' => $email, 'password' => $password]);
            Workspace::create(['name' => trim($workspaceName)])->users()->attach($user->id, ['role' => 'admin']);

            return $user;
        });
    }
}
