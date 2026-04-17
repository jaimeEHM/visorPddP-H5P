<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class LrsConnection extends Model
{
    protected $fillable = [
        'lti_platform_id',
        'name',
        'endpoint_url',
        'basic_username',
        'basic_password',
        'xapi_version',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'basic_password' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(LtiPlatform::class, 'lti_platform_id');
    }
}
