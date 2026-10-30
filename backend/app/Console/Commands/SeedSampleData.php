<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Workspace;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mktly:sample-data {workspace : 워크스페이스 id} {--force : 운영 환경에서 실행 확인}')]
#[Description('비어 있는 워크스페이스에 데모와 같은 샘플 고객, 세그먼트, 캠페인을 채운다 (샘플 고객은 예제 주소라 발송을 끈다)')]
class SeedSampleData extends Command
{
    public function handle(DemoSeeder $seeder): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('운영 환경입니다. 샘플 데이터를 넣으려면 --force 옵션을 함께 지정해 주세요.');

            return self::FAILURE;
        }

        $workspace = Workspace::find((int) $this->argument('workspace'));

        if ($workspace === null) {
            $this->error('워크스페이스를 찾을 수 없습니다.');

            return self::FAILURE;
        }

        // 실제 데이터와 섞이거나 두 번 들어가지 않도록 비어 있는 워크스페이스에만 넣는다.
        if (Contact::withoutGlobalScopes()->where('workspace_id', $workspace->id)->exists()
            || Campaign::withoutGlobalScopes()->where('workspace_id', $workspace->id)->exists()) {
            $this->error("워크스페이스 #{$workspace->id}에 이미 고객이나 캠페인이 있어 샘플 데이터를 넣지 않았습니다.");

            return self::FAILURE;
        }

        $seeder->seedSampleData($workspace);

        $this->info("워크스페이스 #{$workspace->id} ({$workspace->name})에 샘플 데이터를 넣었습니다.");
        $this->line('샘플 고객은 예제 주소라서 이메일 발송을 껐습니다. 실제 고객을 넣은 뒤 mktly:workspace-sending으로 다시 켜 주세요.');

        return self::SUCCESS;
    }
}
