<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'duration_minutes',
        'price',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
