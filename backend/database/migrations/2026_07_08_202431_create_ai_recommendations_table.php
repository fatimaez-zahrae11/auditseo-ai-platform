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
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('audit_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('provider')->nullable(); // openrouter, anthropic, mock
            $table->text('prompt_summary')->nullable();
            $table->longText('generated_text');

            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
    }
};
