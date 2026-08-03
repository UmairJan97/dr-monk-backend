<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_message_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16); // sms|email
            $table->string('template_key', 64);
            $table->string('recipient_hint', 64); // masked email/phone — never full PHI dump
            $table->string('subject', 160)->nullable();
            $table->string('body', 500); // scrubbed template body only
            $table->string('status', 24)->default('queued'); // queued|sent|failed
            $table->string('provider_ref', 120)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'created_at']);
        });

        Schema::create('patient_intake_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('status', 24)->default('open'); // open|completed|expired
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->json('submitted_payload')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_intake_sessions');
        Schema::dropIfExists('clinic_message_logs');
    }
};
