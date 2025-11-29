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
        Schema::create('monthly_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->onDelete('cascade');
            $table->string('name');
            $table->decimal('amount', 15, 2);
            $table->string('account_number');
            $table->integer('day_of_month')->default(1); // Which day to run deduction
            $table->boolean('is_active')->default(true);
            $table->string('created_by');
            $table->timestamps();
            
            $table->index('group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_deductions');
    }
};
