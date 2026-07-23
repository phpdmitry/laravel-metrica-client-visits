<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metrica_visit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->string('external_id');
            $table->string('client_id', 30)->index();
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('goal_id')->nullable();
            $table->boolean('disable_goal_check')->default(false);
            $table->string('status', 32)->default('pending')->index();
            $table->string('reason', 64)->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'external_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('metrica_visit_events'); }
};
