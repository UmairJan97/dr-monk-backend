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
        if (Schema::hasColumn('vitals', 'notes')) {
            return;
        }
        Schema::table('vitals', function (Blueprint $table) {
            $table->string('notes', 500)->nullable()->after('glucose');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vitals') || ! Schema::hasColumn('vitals', 'notes')) {
            return;
        }
        Schema::table('vitals', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
