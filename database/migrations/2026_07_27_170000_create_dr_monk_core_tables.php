<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('billing_period'); // monthly|yearly|trial|enterprise
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedInteger('ai_credits_monthly')->default(0);
            $table->unsignedBigInteger('storage_mb')->default(1024);
            $table->boolean('is_active')->default(true);
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subdomain')->unique()->nullable();
            $table->string('custom_domain')->nullable();
            $table->string('timezone')->default('America/New_York');
            $table->string('logo_path')->nullable();
            $table->json('working_hours')->nullable();
            $table->string('status')->default('active'); // active|suspended|trial|expired
            $table->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_customer_id')->nullable();
            $table->string('stripe_subscription_id')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->unsignedInteger('ai_credits_balance')->default(0);
            $table->unsignedBigInteger('storage_used_mb')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->string('pin_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('can_prescribe')->default(false);
            $table->string('npi')->nullable();
            $table->string('dea')->nullable();
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('sleep_mode_at')->nullable();
        });

        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('mrn')->index();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('date_of_birth');
            $table->string('gender')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('photo_path')->nullable();
            $table->foreignId('primary_provider_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('emergency_contact')->nullable();
            $table->text('allergies')->nullable();
            $table->text('active_medications')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['clinic_id', 'mrn']);
        });

        Schema::create('patient_provider_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['patient_id', 'provider_id']);
        });

        Schema::create('patient_insurances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // primary|secondary
            $table->text('payer_name'); // encrypted at app layer
            $table->text('policy_number'); // encrypted
            $table->text('group_number')->nullable(); // encrypted
            $table->date('expires_on')->nullable();
            $table->string('card_front_path')->nullable();
            $table->string('card_back_path')->nullable();
            $table->json('eligibility_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('users')->cascadeOnDelete();
            $table->string('visit_type')->nullable();
            $table->string('room')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('scheduled');
            // scheduled|waiting|ready_for_vitals|vitals_completed|ready_for_provider|
            // in_progress|completed|follow_up_needed|cancelled|no_show
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'provider_id', 'starts_at', 'ends_at']);
            $table->index(['clinic_id', 'status']);
        });

        Schema::create('vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('weight_kg', 8, 2)->nullable();
            $table->decimal('bmi', 8, 2)->nullable();
            $table->decimal('temperature_c', 5, 2)->nullable();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();
            $table->unsignedTinyInteger('pain_scale')->nullable();
            $table->decimal('glucose', 8, 2)->nullable();
            $table->json('alerts')->nullable();
            $table->timestamps();
        });

        Schema::create('clinical_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('note_type'); // soap|progress|follow_up|consultation|telehealth|session|...
            $table->longText('content');
            $table->json('structured')->nullable(); // SOAP sections etc.
            $table->boolean('is_signed')->default(false);
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->string('icd10_code')->nullable();
            $table->string('description');
            $table->string('status')->default('active'); // active|archived
            $table->timestamps();
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prescriber_id')->constrained('users')->cascadeOnDelete();
            $table->string('medication_name');
            $table->string('sig')->nullable();
            $table->string('quantity')->nullable();
            $table->string('refills')->nullable();
            $table->string('pharmacy')->nullable();
            $table->string('status')->default('draft'); // draft|sent|renewed|cancelled
            $table->string('ncpdp_message_id')->nullable();
            $table->json('surescripts_payload')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ordered_by')->constrained('users')->cascadeOnDelete();
            $table->string('test_name');
            $table->string('status')->default('ordered');
            $table->boolean('is_critical')->default(false);
            $table->string('result_file_path')->nullable();
            $table->text('result_summary')->nullable();
            $table->json('result_values')->nullable();
            $table->timestamp('resulted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('recommendations')->nullable();
            $table->text('home_care')->nullable();
            $table->text('follow_up_plan')->nullable();
            $table->text('referrals')->nullable();
            $table->timestamps();
        });

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->string('status')->default('open');
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        Schema::create('counseling_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counselor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_type')->nullable();
            $table->longText('notes')->nullable();
            $table->json('goals')->nullable();
            $table->timestamps();
        });

        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('administered_by')->constrained('users')->cascadeOnDelete();
            $table->string('instrument'); // PHQ-9|GAD-7|PCL-5|custom
            $table->json('responses');
            $table->unsignedSmallInteger('score')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code_system'); // ICD10|CPT|HCPCS
            $table->string('code');
            $table->string('description')->nullable();
            $table->string('modifier')->nullable();
            $table->string('source')->default('manual'); // manual|ai_suggest
            $table->string('status')->default('suggested'); // suggested|accepted|dismissed|confirmed
            $table->timestamps();
        });

        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->string('clearinghouse_id')->nullable();
            $table->text('x12_payload')->nullable();
            $table->json('denial_codes')->nullable();
            $table->decimal('billed_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->string('method'); // cash|card|online|stripe
            $table->decimal('amount', 12, 2);
            $table->string('stripe_payment_intent_id')->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->string('category');
            $table->decimal('amount', 12, 2);
            $table->text('description')->nullable();
            $table->date('incurred_on')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->string('doc_type')->nullable();
            $table->boolean('is_signed')->default(false);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role')->nullable();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('endpoint')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('result'); // allowed|denied
            $table->json('meta')->nullable(); // never store raw PHI
            $table->timestamp('created_at')->useCurrent();
            $table->index(['clinic_id', 'created_at']);
            $table->index(['patient_id', 'created_at']);
        });

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature'); // voice|summary|coding_suggest|admin_ops
            $table->unsignedInteger('credits_used')->default(1);
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $tables = [
            'ai_usage_logs', 'user_invitations', 'audit_logs', 'documents', 'expenses', 'payments',
            'claims', 'billing_codes', 'assessments', 'counseling_sessions', 'follow_ups',
            'treatment_plans', 'lab_orders', 'prescriptions', 'diagnoses', 'clinical_notes',
            'vitals', 'appointments', 'patient_insurances', 'patient_provider_assignments',
            'patients',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clinic_id');
            $table->dropColumn([
                'phone', 'pin_hash', 'is_active', 'can_prescribe', 'npi', 'dea',
                'failed_login_attempts', 'locked_until', 'last_activity_at', 'sleep_mode_at',
            ]);
        });

        Schema::dropIfExists('clinics');
        Schema::dropIfExists('subscription_plans');
    }
};
