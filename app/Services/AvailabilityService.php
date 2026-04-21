<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Service;
use App\Models\Employee;
use App\Models\Appointment;
use App\Models\EmployeeSchedule;
use App\Models\EmployeeTimeOff;

class AvailabilityService
{
    public function check($branchId, $serviceId, $datetime, $employeeId = null)
    {
        $service = Service::findOrFail($serviceId);

        $start = Carbon::parse($datetime);
        $end = $start->copy()->addMinutes($service->duration_minutes);

        if ($employeeId) {
            $employee = Employee::findOrFail($employeeId);
            return $this->isEmployeeAvailable($employee, $start, $end);
        }

        $employees = Employee::where('branch_id', $branchId)
            ->where('active', true)
            ->get();

        foreach ($employees as $employee) {
            if ($this->isEmployeeAvailable($employee, $start, $end)) {
                return [
                    'available' => true,
                    'employee_id' => $employee->id
                ];
            }
        }

        return ['available' => false];
    }

    private function isEmployeeAvailable($employee, $start, $end)
    {
        $dayOfWeek = $start->dayOfWeek;

        $schedule = EmployeeSchedule::where('employee_id', $employee->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_working_day', true)
            ->first();

        if (!$schedule) return false;

        if (
            $start->format('H:i:s') < $schedule->start_time ||
            $end->format('H:i:s') > $schedule->end_time
        ) {
            return false;
        }

        if ($schedule->break_start && $schedule->break_end) {
            if (
                $start->format('H:i:s') < $schedule->break_end &&
                $end->format('H:i:s') > $schedule->break_start
            ) {
                return false;
            }
        }

        $isOff = EmployeeTimeOff::where('employee_id', $employee->id)
            ->whereDate('start_date', '<=', $start->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();

        if ($isOff) return false;

        $overlap = Appointment::where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('start_time', [$start, $end])
                    ->orWhereBetween('end_time', [$start, $end])
                    ->orWhere(function ($q) use ($start, $end) {
                        $q->where('start_time', '<=', $start)
                            ->where('end_time', '>=', $end);
                    });
            })
            ->exists();

        if ($overlap) return false;

        return true;
    }
    public function getAvailableSlots($branchId, $serviceId, $date, $employeeId = null)
    {
        $service = Service::findOrFail($serviceId);

        $date = Carbon::parse($date);

        // Define working window (you can improve later per employee)
        $startOfDay = $date->copy()->setTime(9, 0);
        $endOfDay = $date->copy()->setTime(18, 0);

        $slots = [];

        $current = $startOfDay->copy();

        while ($current < $endOfDay) {

            $result = $this->check(
                $branchId,
                $serviceId,
                $current->toDateTimeString(),
                $employeeId
            );

            if (is_array($result) && $result['available']) {
                $slots[] = $current->format('H:i');
            }

            // Move in 30 min steps
            $current->addMinutes(30);
        }

        return $slots;
    }
}
