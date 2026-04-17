<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LtiH5pInstance extends Model
{
    protected $fillable = [
        'issuer',
        'deployment_id',
        'context_id',
        'resource_link_id',
        'preview_id',
        'preview_token',
    ];
}
