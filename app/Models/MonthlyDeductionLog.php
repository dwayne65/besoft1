<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyDeductionLog extends Model
{
    protected $fillable = [
        'deduction_id',
        'member_id',
        'wallet_id',
        'scheduled_date',
        'actual_run_date',
        'amount_attempted',
        'amount_deducted',
        'status',
        'note',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'actual_run_date' => 'datetime',
        'amount_attempted' => 'decimal:2',
        'amount_deducted' => 'decimal:2',
    ];

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(MonthlyDeduction::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
