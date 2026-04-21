<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSchedule extends Model
{
    protected $fillable = [
        'employee_id',
        'day_of_week',
        'start_time',
        'end_time',
        'break_start',
        'break_end',
        'is_working_day',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
