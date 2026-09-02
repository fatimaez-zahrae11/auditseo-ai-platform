<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_geolocations', function (Blueprint $table) {
            $table->id();
            $table->char('ip_hash', 64)->unique();
            $table->string('ip_masked', 64);
            $table->string('country_code', 2)->nullable();
            $table->string('country_name', 100)->nullable();
            $table->string('region', 150)->nullable();
            $table->string('city', 150)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 100)->nullable();
            $table->string('isp', 200)->nullable();
            $table->string('source', 50)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('country_code');
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_geolocations');
    }
};
