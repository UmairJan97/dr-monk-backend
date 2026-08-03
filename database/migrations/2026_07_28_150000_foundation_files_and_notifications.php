<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->string('body', 500);
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['clinic_id', 'user_id', 'read_at']);
            $table->index(['clinic_id', 'created_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(true)->after('file_path');
            $table->string('mime_type', 120)->nullable()->after('is_encrypted');
            $table->unsignedBigInteger('byte_size')->nullable()->after('mime_type');
            $table->string('checksum_sha256', 64)->nullable()->after('byte_size');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['is_encrypted', 'mime_type', 'byte_size', 'checksum_sha256']);
        });

        Schema::dropIfExists('clinic_notifications');
    }
};
