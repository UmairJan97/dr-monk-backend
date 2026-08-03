<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->json('notification_templates')->nullable()->after('working_hours');
            $table->json('hipaa_settings')->nullable()->after('notification_templates');
        });
    }

    public function down(): void
    {
        Schema::table('clinics', function (Blueprint $table) {
            $table->dropColumn(['notification_templates', 'hipaa_settings']);
        });
    }
};
