<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Member extends Model
{
    protected $fillable = [
        'first_name',
        'last_name',
        'birth_date',
        'gender',
        'is_active',
        'national_id',
        'phone',
        'group_id',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::created(function ($member) {
            // Auto-create wallet for new member
            Wallet::create([
                'member_id' => $member->id,
                'balance' => 0,
                'currency' => 'RWF',
                'is_active' => true,
            ]);
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }
}
