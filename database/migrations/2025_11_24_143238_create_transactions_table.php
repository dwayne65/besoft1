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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id')->unique();
            $table->integer('amount');
            $table->string('currency', 10);
            $table->bigInteger('phone');
            $table->string('payment_mode', 20);
            $table->text('message')->nullable();
            $table->string('callback_url')->nullable();
            $table->integer('status')->default(201); // 201=Pending, 200=Success, 400=Failed
            $table->json('transfers')->nullable();
            $table->json('mopay_response')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
