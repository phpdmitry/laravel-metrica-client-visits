<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Older versions could copy an already locally interpreted candidate time
     * into visit_started_at. The candidate row is the source of truth and was
     * stored as UTC, so restore match values from it without guessing offsets.
     */
    public function up(): void
    {
        DB::table('metrica_visit_matches')
            ->whereNotNull('candidate_id')
            ->orderBy('id')
            ->chunkById(100, function ($matches): void {
                foreach ($matches as $match) {
                    $startedAt = DB::table('metrica_visit_candidates')
                        ->where('id', $match->candidate_id)
                        ->value('started_at');

                    if ($startedAt !== null) {
                        DB::table('metrica_visit_matches')
                            ->where('id', $match->id)
                            ->update(['visit_started_at' => $startedAt]);
                    }
                }
            });
    }

    public function down(): void
    {
        // The original offset is ambiguous; the correction is intentionally irreversible.
    }
};
