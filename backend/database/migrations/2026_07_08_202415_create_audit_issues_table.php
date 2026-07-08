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
        Schema::create('audit_issues', function (Blueprint $table) {
            $table->id();

            $table->foreignId('audit_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('category');
            $table->string('title');
            $table->string('severity'); // critical, important, minor
            $table->text('description')->nullable();
            $table->text('recommendation')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_issues');
    }
};
