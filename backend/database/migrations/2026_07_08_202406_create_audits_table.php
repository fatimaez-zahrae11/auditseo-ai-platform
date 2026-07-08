<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::create('audits', function (Blueprint $table) {
        $table->id();

        $table->foreignId('domain_id')
            ->constrained()
            ->cascadeOnDelete();

        $table->integer('global_score')->default(0);
        $table->integer('technical_score')->default(0);
        $table->integer('content_score')->default(0);
        $table->integer('links_score')->default(0);
        $table->integer('performance_score')->default(0);

        $table->json('raw_data')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
