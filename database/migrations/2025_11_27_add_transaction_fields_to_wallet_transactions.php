<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Add direction field (credit or debit)
            $table->enum('direction', ['credit', 'debit'])->after('amount')->nullable();
            
            // Add initiated_by field (system, group_admin, group_user, member)
            $table->enum('initiated_by', ['system', 'group_admin', 'group_user', 'member', 'super_admin'])->after('created_by')->nullable();
            
            // Add processed_at timestamp
            $table->timestamp('processed_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn(['direction', 'initiated_by', 'processed_at']);
        });
    }
};
