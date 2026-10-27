<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * 워크스페이스에 속한 사용자를 만든다.
 *
 * @return array{0: User, 1: Workspace}
 */
function userInWorkspace(string $role = 'admin', string $workspaceName = '테스트 스토어'): array
{
    $workspace = Workspace::create(['name' => $workspaceName]);
    $user = User::factory()->create();
    $workspace->users()->attach($user->id, ['role' => $role]);

    return [$user, $workspace];
}
