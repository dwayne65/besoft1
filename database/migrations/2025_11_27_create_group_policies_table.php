<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->onDelete('cascade');
            $table->boolean('allow_group_user_cashout')->default(true);
            $table->boolean('allow_member_withdrawal')->default(true);
            $table->decimal('max_cashout_amount', 15, 2)->nullable();
            $table->decimal('max_withdrawal_amount', 15, 2)->nullable();
            $table->boolean('require_approval_for_withdrawal')->default(true);
            $table->timestamps();
            
            $table->unique('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_policies');
    }
};
