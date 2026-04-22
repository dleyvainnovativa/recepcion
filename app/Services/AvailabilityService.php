<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\EmployeeSchedule;
use App\Models\EmployeeTimeOff;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /**
     * Check whether a specific slot is free for a given branch/service/employee.
     *
     * @param  int          $branchId
     * @param  int          $serviceId
     * @param  int|null     $employeeId   If null, checks any employee in the branch.
     * @param  Carbon       $start
     * @param  Carbon       $end
     * @return bool
     */
    public function isSlotFree(
        int     $branchId,
        int     $serviceId,
        ?int    $employeeId,
        Carbon  $start,
        Carbon  $end,
    ): bool {
        $query = Appointment::where('branch_id', $branchId)
            ->where('status', 'scheduled')
            ->where(function ($q) use ($start, $end) {
                // Overlap condition: existing appointment overlaps with [start, end)
                $q->where('start_time', '<', $end)
                    ->where('end_time',   '>',  $start);
            });

        if ($employeeId) {
            $query->where('employee_id', $employeeId);
        }

        if ($query->exists()) {
            return false; // Slot is taken
        }

        // If employee given, also verify they work that day and aren't on time-off
        if ($employeeId) {
            if (!$this->employeeWorksAt($employeeId, $start)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Suggest up to $count available slots near a requested time.
     *
     * Strategy: scan the next 7 days in 30-minute increments and collect free slots.
     *
     * @return Collection<Carbon>
     */
    public function suggestNearbySlots(
        int     $branchId,
        int     $serviceId,
        Carbon  $around,
        int     $count      = 3,
        ?int    $employeeId = null,
    ): Collection {
        $service  = \App\Models\Service::find($serviceId);
        $duration = $service?->duration_minutes ?? 60;

        $suggestions = collect();
        $candidate   = $around->copy()->roundMinutes(30);

        // Search forward up to 7 days
        $limit = $around->copy()->addDays(7);

        while ($candidate->lessThan($limit) && $suggestions->count() < $count) {
            $candidateEnd = $candidate->copy()->addMinutes($duration);

            if (
                $candidate->isAfter(now()) &&
                $candidate->hour >= 8 &&
                $candidateEnd->hour <= 21 &&
                $this->isSlotFree($branchId, $serviceId, $employeeId, $candidate, $candidateEnd)
            ) {
                $suggestions->push($candidate->copy());
            }

            $candidate->addMinutes(30);
        }

        return $suggestions;
    }

    /**
     * Verify that an employee is scheduled to work at the given datetime
     * and is not on approved time-off.
     */
    private function employeeWorksAt(int $employeeId, Carbon $datetime): bool
    {
        // day_of_week: 0 = Sunday … 6 = Saturday (matches Carbon->dayOfWeek)
        $schedule = EmployeeSchedule::where('employee_id', $employeeId)
            ->where('day_of_week', $datetime->dayOfWeek)
            ->where('is_working_day', 1)
            ->first();

        if (!$schedule) {
            return false;
        }

        // Check shift hours
        $shiftStart = Carbon::createFromTimeString($schedule->start_time);
        $shiftEnd   = Carbon::createFromTimeString($schedule->end_time);
        $apptTime   = Carbon::createFromTimeString($datetime->format('H:i'));

        if ($apptTime->lessThan($shiftStart) || $apptTime->greaterThanOrEqualTo($shiftEnd)) {
            return false;
        }

        // Check break
        if ($schedule->break_start && $schedule->break_end) {
            $breakStart = Carbon::createFromTimeString($schedule->break_start);
            $breakEnd   = Carbon::createFromTimeString($schedule->break_end);
            if ($apptTime->between($breakStart, $breakEnd)) {
                return false;
            }
        }

        // Check time-off
        $onTimeOff = EmployeeTimeOff::where('employee_id', $employeeId)
            ->where('start_date', '<=', $datetime->toDateString())
            ->where('end_date',   '>=', $datetime->toDateString())
            ->exists();

        return !$onTimeOff;
    }
}
