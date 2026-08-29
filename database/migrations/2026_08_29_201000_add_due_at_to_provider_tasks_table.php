<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_tasks', function (Blueprint $table) {
            $table->timestamp('due_at')->nullable()->after('due_label');
        });
    }

    public function down(): void
    {
        Schema::table('provider_tasks', function (Blueprint $table) {
            $table->dropColumn('due_at');
        });
    }
};
