<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyDeduction extends Model
{
    protected $fillable = [
        'group_id',
        'name',
        'type',
        'amount',
        'percentage',
        'target_account_number',
        'run_day_of_month',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'is_active' => 'boolean',
        'run_day_of_month' => 'integer',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
