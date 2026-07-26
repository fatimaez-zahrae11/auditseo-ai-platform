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
        Schema::table('audits', function (Blueprint $table) {
            $table->index('domain_id', 'idx_audits_domain_id');
        });

        Schema::table('audit_issues', function (Blueprint $table) {
            $table->index('audit_id', 'idx_audit_issues_audit_id');
        });

        Schema::table('ai_recommendations', function (Blueprint $table) {
            $table->index('audit_id', 'idx_ai_recommendations_audit_id');
        });

        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->index('user_id', 'idx_api_usage_logs_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audits', function (Blueprint $table) {
            $table->dropIndex('idx_audits_domain_id');
        });

        Schema::table('audit_issues', function (Blueprint $table) {
            $table->dropIndex('idx_audit_issues_audit_id');
        });

        Schema::table('ai_recommendations', function (Blueprint $table) {
            $table->dropIndex('idx_ai_recommendations_audit_id');
        });

        Schema::table('api_usage_logs', function (Blueprint $table) {
            $table->dropIndex('idx_api_usage_logs_user_id');
        });
    }
};
