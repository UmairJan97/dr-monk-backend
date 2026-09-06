<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_acl_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->string('base_role');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['clinic_id', 'slug']);
            $table->unique(['clinic_id', 'name']);
            $table->index(['clinic_id', 'is_system']);
        });

        Schema::create('clinic_acl_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_acl_role_id')->constrained('clinic_acl_roles')->cascadeOnDelete();
            $table->string('permission_key');
            $table->timestamps();

            $table->unique(['clinic_acl_role_id', 'permission_key'], 'clinic_acl_role_perm_unique');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('clinic_acl_role_id')
                ->nullable()
                ->constrained('clinic_acl_roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('clinic_acl_role_id');
        });
        Schema::dropIfExists('clinic_acl_role_permissions');
        Schema::dropIfExists('clinic_acl_roles');
    }
};
