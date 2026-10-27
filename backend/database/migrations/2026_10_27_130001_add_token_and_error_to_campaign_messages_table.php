<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_messages', function (Blueprint $table) {
            // 수신자별 추적 토큰(오픈 픽셀/클릭 링크에서 수신자를 식별한다). 시드 데이터는 null일 수 있다.
            $table->string('token', 40)->nullable()->unique()->after('contact_id');
            $table->string('error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_messages', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn(['token', 'error']);
        });
    }
};
