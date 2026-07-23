<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metrica_visit_log_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->unsignedBigInteger('request_id')->nullable()->unique();
            $table->date('date1');
            $table->date('date2');
            $table->string('status', 32)->default('planned')->index();
            $table->unsignedBigInteger('size')->nullable();
            $table->json('parts')->nullable();
            $table->string('lock_key')->nullable();
            $table->string('lock_owner')->nullable();
            $table->unsignedTinyInteger('poll_attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('metrica_visit_log_requests'); }
};
