<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit balances: what each user can still spend, plus every entry that changed it.
     * Money is in nanodollars (1 USD = 1,000,000,000).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The available balance, after holds for images in progress. Every account starts at $0.
            $table->bigInteger('balance_nano')->default(0);
        });

        // The database itself refuses a negative balance, whatever the code does.
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_balance_not_negative CHECK (balance_nano >= 0)');

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            // The wallet goes with the account. Its generations stay, as billing records.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // An entry must never lose its generation, and the app never deletes generations.
            $table->foreignId('generation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type');
            // Signed: purchase +, hold -, release +, charge -.
            $table->bigInteger('amount_nano');
            $table->timestamp('created_at')->useCurrent();

            // One hold, one release and one charge per generation, at most. Purchases have no
            // generation, and Postgres allows many nulls in a unique index.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_balance_not_negative');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance_nano');
        });
    }
};
