<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('metrica_visit_log_requests', function (Blueprint $table): void {
            $table->string('failure_stage', 32)->nullable()->after('error_message');
        });
    }
    public function down(): void
    {
        Schema::table('metrica_visit_log_requests', function (Blueprint $table): void { $table->dropColumn('failure_stage'); });
    }
};
