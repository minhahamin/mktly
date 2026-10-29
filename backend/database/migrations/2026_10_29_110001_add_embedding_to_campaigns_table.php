<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // 제목과 본문의 임베딩(256차원). 같은 공급자의 벡터끼리만 비교할 수 있으므로 공급자를 함께 기록한다.
            $table->json('embedding')->nullable()->after('sent_at');
            $table->string('embedding_provider', 20)->nullable()->after('embedding');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_provider']);
        });
    }
};
