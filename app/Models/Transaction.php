<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'amount',
        'currency',
        'phone',
        'payment_mode',
        'message',
        'callback_url',
        'status',
        'transfers',
        'mopay_response',
    ];

    protected $casts = [
        'transfers' => 'array',
        'mopay_response' => 'array',
    ];
}
