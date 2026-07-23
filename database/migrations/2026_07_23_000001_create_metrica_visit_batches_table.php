<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metrica_visit_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('fingerprint', 64)->unique();
            $table->unsignedBigInteger('counter_id');
            $table->string('attribution', 64);
            $table->unsignedSmallInteger('lookback_days');
            $table->unsignedInteger('time_tolerance_seconds');
            $table->date('planned_date1');
            $table->date('planned_date2');
            $table->string('status', 32)->index();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('metrica_visit_batches'); }
};
