<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'phone',
        'active',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function schedules()
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    public function timeOffs()
    {
        return $this->hasMany(EmployeeTimeOff::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
