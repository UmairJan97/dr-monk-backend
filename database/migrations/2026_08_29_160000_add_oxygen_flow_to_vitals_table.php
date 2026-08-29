<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vitals')) {
            return;
        }
        if (Schema::hasColumn('vitals', 'oxygen_flow')) {
            return;
        }
        Schema::table('vitals', function (Blueprint $table) {
            $table->decimal('oxygen_flow', 5, 2)->nullable()->after('spo2');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vitals') || ! Schema::hasColumn('vitals', 'oxygen_flow')) {
            return;
        }
        Schema::table('vitals', function (Blueprint $table) {
            $table->dropColumn('oxygen_flow');
        });
    }
};
