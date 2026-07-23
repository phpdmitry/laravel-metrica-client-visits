<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('metrica_visit_events', function (Blueprint $table): void {
            $table->foreign('batch_id', 'metrica_events_batch_fk')->references('id')->on('metrica_visit_batches')->cascadeOnDelete();
        });
        Schema::table('metrica_visit_log_requests', function (Blueprint $table): void {
            $table->foreign('batch_id', 'metrica_log_requests_batch_fk')->references('id')->on('metrica_visit_batches')->cascadeOnDelete();
        });
        Schema::table('metrica_visit_candidates', function (Blueprint $table): void {
            $table->foreign('event_id', 'metrica_candidates_event_fk')->references('id')->on('metrica_visit_events')->cascadeOnDelete();
            $table->foreign('log_request_id', 'metrica_candidates_request_fk')->references('id')->on('metrica_visit_log_requests')->cascadeOnDelete();
        });
        Schema::table('metrica_visit_matches', function (Blueprint $table): void {
            $table->foreign('batch_id', 'metrica_matches_batch_fk')->references('id')->on('metrica_visit_batches')->cascadeOnDelete();
            $table->foreign('event_id', 'metrica_matches_event_fk')->references('id')->on('metrica_visit_events')->cascadeOnDelete();
            $table->foreign('candidate_id', 'metrica_matches_candidate_fk')->references('id')->on('metrica_visit_candidates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('metrica_visit_matches', function (Blueprint $table): void {
            $table->dropForeign('metrica_matches_batch_fk'); $table->dropForeign('metrica_matches_event_fk'); $table->dropForeign('metrica_matches_candidate_fk');
        });
        Schema::table('metrica_visit_candidates', function (Blueprint $table): void {
            $table->dropForeign('metrica_candidates_event_fk'); $table->dropForeign('metrica_candidates_request_fk');
        });
        Schema::table('metrica_visit_log_requests', function (Blueprint $table): void { $table->dropForeign('metrica_log_requests_batch_fk'); });
        Schema::table('metrica_visit_events', function (Blueprint $table): void { $table->dropForeign('metrica_events_batch_fk'); });
    }
};
