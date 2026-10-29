<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('content');
            // 256차원 임베딩. 같은 공급자의 벡터끼리만 비교할 수 있으므로 공급자를 함께 기록한다.
            $table->json('embedding')->nullable();
            $table->string('embedding_provider', 20)->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'id']);
            $table->index(['brand_document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_chunks');
    }
};
