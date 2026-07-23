<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metrica_visit_candidates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('event_id')->index();
            $table->unsignedBigInteger('log_request_id')->index();
            $table->string('visit_id', 32);
            $table->timestamp('started_at');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('source', 64)->nullable();
            $table->text('source_detail')->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->text('utm_campaign')->nullable();
            $table->text('referrer')->nullable();
            $table->text('start_url')->nullable();
            $table->json('goal_ids')->nullable();
            $table->json('goal_times')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'visit_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('metrica_visit_candidates'); }
};
