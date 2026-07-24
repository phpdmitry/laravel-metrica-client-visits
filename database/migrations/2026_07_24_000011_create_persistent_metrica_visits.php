<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metrica_visits', function (Blueprint $table): void {
            $table->id();
            $table->string('counter_id', 32);
            $table->string('visit_id', 32);
            $table->string('client_id', 30)->index();
            $table->timestamp('started_at')->index();
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
            $table->unique(['counter_id', 'visit_id'], 'metrica_visits_counter_visit_unique');
        });

        Schema::table('metrica_visit_events', function (Blueprint $table): void {
            $table->string('counter_id', 32)->nullable()->after('batch_id');
            $table->string('event_name', 255)->default('Целевое действие')->after('occurred_at');
            $table->unsignedBigInteger('primary_visit_id')->nullable()->after('disable_goal_check');
            $table->index(['counter_id', 'client_id'], 'metrica_events_counter_client_index');
        });

        Schema::create('metrica_visit_event_visit', function (Blueprint $table): void {
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('log_request_id')->nullable();
            $table->timestamps();
            $table->primary(['event_id', 'visit_id']);
            $table->index('visit_id');
        });

        // Старые записи остаются читаемыми через новый API после обновления пакета.
        DB::table('metrica_visit_events')->orderBy('id')->each(function (object $event): void {
            $counterId = DB::table('metrica_visit_batches')->where('id', $event->batch_id)->value('counter_id');
            if ($counterId !== null) {
                DB::table('metrica_visit_events')->where('id', $event->id)->update(['counter_id' => $counterId]);
            }
        });
        DB::table('metrica_visit_candidates')->orderBy('id')->each(function (object $candidate): void {
            $event = DB::table('metrica_visit_events')->where('id', $candidate->event_id)->first();
            if ($event === null || $event->counter_id === null) {
                return;
            }
            DB::table('metrica_visits')->upsert([[
                'counter_id' => $event->counter_id, 'visit_id' => $candidate->visit_id, 'client_id' => $event->client_id,
                'started_at' => $candidate->started_at, 'duration_seconds' => $candidate->duration_seconds,
                'source' => $candidate->source, 'source_detail' => $candidate->source_detail,
                'utm_source' => $candidate->utm_source, 'utm_medium' => $candidate->utm_medium,
                'utm_campaign' => $candidate->utm_campaign, 'referrer' => $candidate->referrer,
                'start_url' => $candidate->start_url, 'goal_ids' => $candidate->goal_ids,
                'goal_times' => $candidate->goal_times, 'created_at' => $candidate->created_at, 'updated_at' => $candidate->updated_at,
            ]], ['counter_id', 'visit_id'], ['client_id', 'started_at', 'duration_seconds', 'source', 'source_detail', 'utm_source', 'utm_medium', 'utm_campaign', 'referrer', 'start_url', 'goal_ids', 'goal_times', 'updated_at']);
            $visitId = DB::table('metrica_visits')->where(['counter_id' => $event->counter_id, 'visit_id' => $candidate->visit_id])->value('id');
            DB::table('metrica_visit_event_visit')->updateOrInsert(
                ['event_id' => $event->id, 'visit_id' => $visitId],
                ['log_request_id' => $candidate->log_request_id, 'created_at' => $candidate->created_at, 'updated_at' => $candidate->updated_at],
            );
        });
        DB::table('metrica_visit_matches')->whereNotNull('candidate_id')->orderBy('id')->each(function (object $match): void {
            $candidate = DB::table('metrica_visit_candidates')->where('id', $match->candidate_id)->first();
            $event = $candidate === null ? null : DB::table('metrica_visit_events')->where('id', $candidate->event_id)->first();
            if ($event === null || $event->counter_id === null) {
                return;
            }
            $visitId = DB::table('metrica_visits')->where(['counter_id' => $event->counter_id, 'visit_id' => $candidate->visit_id])->value('id');
            DB::table('metrica_visit_events')->where('id', $event->id)->update(['primary_visit_id' => $visitId]);
        });

        Schema::table('metrica_visit_events', function (Blueprint $table): void {
            $table->unique(['counter_id', 'client_id', 'occurred_at', 'event_name'], 'metrica_events_natural_unique');
            $table->foreign('primary_visit_id', 'metrica_events_primary_visit_fk')->references('id')->on('metrica_visits')->nullOnDelete();
        });
        Schema::table('metrica_visit_event_visit', function (Blueprint $table): void {
            $table->foreign('event_id', 'metrica_event_visit_event_fk')->references('id')->on('metrica_visit_events')->cascadeOnDelete();
            $table->foreign('visit_id', 'metrica_event_visit_visit_fk')->references('id')->on('metrica_visits')->cascadeOnDelete();
            $table->foreign('log_request_id', 'metrica_event_visit_request_fk')->references('id')->on('metrica_visit_log_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::dropIfExists('metrica_visit_event_visit');
            Schema::dropIfExists('metrica_visits');
            return;
        }
        Schema::table('metrica_visit_event_visit', function (Blueprint $table): void {
            $table->dropForeign('metrica_event_visit_event_fk');
            $table->dropForeign('metrica_event_visit_visit_fk');
            $table->dropForeign('metrica_event_visit_request_fk');
        });
        Schema::table('metrica_visit_events', function (Blueprint $table): void {
            $table->dropForeign('metrica_events_primary_visit_fk');
            $table->dropUnique('metrica_events_natural_unique');
            $table->dropIndex('metrica_events_counter_client_index');
            $table->dropColumn(['counter_id', 'event_name', 'primary_visit_id']);
        });
        Schema::dropIfExists('metrica_visit_event_visit');
        Schema::dropIfExists('metrica_visits');
    }
};
