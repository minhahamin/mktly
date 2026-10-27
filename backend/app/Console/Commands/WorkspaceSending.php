<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mktly:workspace-sending {workspace : 워크스페이스 id} {state : on 또는 off}')]
#[Description('워크스페이스의 이메일 실제 발송 허용 여부를 켜거나 끈다 (공개 회원가입 워크스페이스는 기본 꺼짐)')]
class WorkspaceSending extends Command
{
    public function handle(): int
    {
        $state = strtolower((string) $this->argument('state'));

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('state는 on 또는 off여야 합니다.');

            return self::FAILURE;
        }

        $workspace = Workspace::find((int) $this->argument('workspace'));

        if ($workspace === null) {
            $this->error('워크스페이스를 찾을 수 없습니다.');

            return self::FAILURE;
        }

        $workspace->forceFill(['sending_enabled' => $state === 'on'])->save();

        $this->info("워크스페이스 #{$workspace->id} ({$workspace->name}): 이메일 발송 ".($state === 'on' ? '허용' : '차단'));

        return self::SUCCESS;
    }
}
