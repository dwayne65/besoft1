<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_deductions', function (Blueprint $table) {
            // Add type field (fixed_amount or percentage_of_balance)
            $table->enum('type', ['fixed_amount', 'percentage_of_balance'])->default('fixed_amount')->after('name');
            
            // Add percentage field for percentage-based deductions
            $table->decimal('percentage', 5, 2)->nullable()->after('amount');
            
            // Rename account_number to target_account_number for clarity
            $table->renameColumn('account_number', 'target_account_number');
            
            // Rename day_of_month to run_day_of_month for clarity
            $table->renameColumn('day_of_month', 'run_day_of_month');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_deductions', function (Blueprint $table) {
            $table->dropColumn(['type', 'percentage']);
            $table->renameColumn('target_account_number', 'account_number');
            $table->renameColumn('run_day_of_month', 'day_of_month');
        });
    }
};
