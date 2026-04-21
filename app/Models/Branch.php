<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'address',
        'phone',
    ];

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
