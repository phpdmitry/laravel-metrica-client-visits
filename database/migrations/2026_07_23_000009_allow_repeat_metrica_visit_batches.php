<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('metrica_visit_batches', function (Blueprint $table): void {
            $table->string('active_fingerprint', 64)->nullable()->after('fingerprint');
            $table->string('selection_strategy', 16)->default('last')->after('attribution');
        });

        DB::table('metrica_visit_batches')->orderBy('id')->each(function (object $batch): void {
            $activeFingerprint = in_array($batch->status, ['queued', 'planning', 'exporting'], true)
                ? $batch->fingerprint
                : 'released:' . $batch->id;

            DB::table('metrica_visit_batches')->where('id', $batch->id)->update(['active_fingerprint' => $activeFingerprint]);
        });

        Schema::table('metrica_visit_batches', function (Blueprint $table): void {
            $table->dropUnique(['fingerprint']);
            $table->unique('active_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('metrica_visit_batches', function (Blueprint $table): void {
            $table->dropUnique(['active_fingerprint']);
            $table->dropColumn(['active_fingerprint', 'selection_strategy']);
        });
    }
};
