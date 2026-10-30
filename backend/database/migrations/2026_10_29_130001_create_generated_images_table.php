<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_generation_id')->nullable()->constrained('ai_generations')->nullOnDelete();
            $table->uuid('set_uuid');
            $table->string('title', 100);
            $table->string('kind', 10);
            $table->unsignedTinyInteger('slide_index');
            $table->string('ratio', 5);
            // 화면의 대체 텍스트로 쓰는 슬라이드 제목(최대 20자)
            $table->string('slide_title', 40);
            // 운영 컨테이너의 디스크는 재배포 때 사라지므로 PNG를 DB에 둔다. 내려줄 때는 인증된 라우트만 거친다.
            $table->binary('data');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('background_provider', 10);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'set_uuid']);
        });

        // Laravel의 binary()는 MySQL에서 BLOB(64KB)이라 PNG가 들어가지 않는다.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement('ALTER TABLE generated_images MODIFY data MEDIUMBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_images');
    }
};
