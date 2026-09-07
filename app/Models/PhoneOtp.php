<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneOtp extends Model
{
    public const PHONE_VERIFICATION = 'phone_verification';

    public const PASSWORD_RESET = 'password_reset';

    protected $fillable = [
        'user_id',
        'purpose',
        'code_hash',
        'expires_at',
        'attempts',
        'last_sent_at',
        'reset_token_hash',
        'reset_token_expires_at',
    ];

    protected $hidden = ['code_hash', 'reset_token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
        ];
    }
}
