<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LtiPlatform extends Model
{
    protected $fillable = [
        'name',
        'issuer',
        'client_id',
        'jwks_json',
        'jwks_url',
        'authorization_endpoint',
        'token_endpoint',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'jwks_json' => 'array',
            'active' => 'boolean',
        ];
    }
}
