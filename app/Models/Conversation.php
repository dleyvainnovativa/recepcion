<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'phone',
        'current_step',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];
}
