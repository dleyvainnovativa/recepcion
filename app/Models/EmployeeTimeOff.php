<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeTimeOff extends Model
{
    protected $table = 'employee_time_off';

    protected $fillable = [
        'employee_id',
        'start_date',
        'end_date',
        'reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
