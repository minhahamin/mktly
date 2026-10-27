<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // 이메일 실제 발송 허용 여부. 기존 워크스페이스는 허용(true)이고, 공개 회원가입으로 만든 워크스페이스는
            // 스팸 남용을 막기 위해 false로 시작한다(운영자가 mktly:workspace-sending으로 켠다).
            $table->boolean('sending_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('sending_enabled');
        });
    }
};
