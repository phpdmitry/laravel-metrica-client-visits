<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('metrica_visit_log_requests', function (Blueprint $table): void {
            $table->uuid('creation_token')->nullable()->unique()->after('lock_owner');
            $table->timestamp('create_attempted_at')->nullable()->after('creation_token');
        });
    }
    public function down(): void
    {
        Schema::table('metrica_visit_log_requests', function (Blueprint $table): void {
            $table->dropUnique(['creation_token']);
            $table->dropColumn(['creation_token', 'create_attempted_at']);
        });
    }
};
