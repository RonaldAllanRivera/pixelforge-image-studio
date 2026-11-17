<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // The token counts behind the estimate and the maximum, shown next to the actual ones.
            // Null for generations created before this column existed.
            $table->json('quoted_usage')->nullable()->after('max_charge_nano');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('quoted_usage');
        });
    }
};
