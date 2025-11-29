<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPolicy extends Model
{
    protected $fillable = [
        'group_id',
        'allow_group_user_cashout',
        'allow_member_withdrawal',
        'max_cashout_amount',
        'max_withdrawal_amount',
        'require_approval_for_withdrawal',
    ];

    protected $casts = [
        'allow_group_user_cashout' => 'boolean',
        'allow_member_withdrawal' => 'boolean',
        'require_approval_for_withdrawal' => 'boolean',
        'max_cashout_amount' => 'decimal:2',
        'max_withdrawal_amount' => 'decimal:2',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
