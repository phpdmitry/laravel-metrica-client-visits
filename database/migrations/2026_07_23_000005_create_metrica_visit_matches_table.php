<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metrica_visit_matches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->unsignedBigInteger('event_id')->unique();
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->string('match_type', 32)->nullable();
            $table->string('confidence', 32)->nullable();
            $table->string('reason', 64)->nullable();
            $table->boolean('goal_confirmed')->default(false);
            $table->string('visit_id', 32)->nullable();
            $table->timestamp('visit_started_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('source', 64)->nullable();
            $table->text('source_detail')->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->text('utm_campaign')->nullable();
            $table->text('referrer')->nullable();
            $table->text('start_url')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('metrica_visit_matches'); }
};
