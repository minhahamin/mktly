<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->json('tags')->nullable();
            $table->date('joined_at')->nullable();
            $table->date('last_purchase_at')->nullable();
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->decimal('churn_score', 5, 4)->nullable();
            $table->unsignedSmallInteger('cluster_id')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'email']);
            $table->index(['workspace_id', 'last_purchase_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
