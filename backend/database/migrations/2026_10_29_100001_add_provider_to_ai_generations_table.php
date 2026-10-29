<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('output');
            $table->index(['type', 'provider', 'created_at']);
        });

        // 공급자를 기록하기 전 데이터: 샘플은 mock, 실제 모델 결과는 당시 사용하던 anthropic.
        DB::table('ai_generations')->where('mock', true)->update(['provider' => 'mock']);
        DB::table('ai_generations')->where('mock', false)->update(['provider' => 'anthropic']);
    }

    public function down(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->dropIndex(['type', 'provider', 'created_at']);
            $table->dropColumn('provider');
        });
    }
};
