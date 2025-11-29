<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_deduction_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deduction_id')->constrained('monthly_deductions')->onDelete('cascade');
            $table->foreignId('member_id')->constrained('members')->onDelete('cascade');
            $table->foreignId('wallet_id')->constrained('wallets')->onDelete('cascade');
            $table->date('scheduled_date');
            $table->dateTime('actual_run_date')->nullable();
            $table->decimal('amount_attempted', 15, 2);
            $table->decimal('amount_deducted', 15, 2)->default(0);
            $table->enum('status', ['success', 'partial', 'failed', 'insufficient_balance', 'skipped'])->default('failed');
            $table->text('note')->nullable();
            $table->timestamps();
            
            $table->index(['deduction_id', 'scheduled_date']);
            $table->index(['member_id', 'scheduled_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_deduction_logs');
    }
};
