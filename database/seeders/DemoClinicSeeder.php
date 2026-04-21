<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Branch;
use App\Models\Service;
use App\Models\Employee;
use App\Models\EmployeeSchedule;

class DemoClinicSeeder extends Seeder
{
    public function run(): void
    {
        // =========================
        // BRANCHES
        // =========================
        $branch1 = Branch::create([
            'name' => 'Sucursal Centro',
            'address' => 'Centro, Ciudad',
        ]);

        $branch2 = Branch::create([
            'name' => 'Sucursal Norte',
            'address' => 'Zona Norte, Ciudad',
        ]);

        // =========================
        // SERVICES (per branch)
        // =========================
        $durations = [
            ['name' => 'Servicio 30 min', 'duration_minutes' => 30, 'price' => 200],
            ['name' => 'Servicio 1 hora', 'duration_minutes' => 60, 'price' => 350],
            ['name' => 'Servicio 2 horas', 'duration_minutes' => 120, 'price' => 600],
        ];

        foreach ($durations as $service) {
            Service::create([...$service, 'branch_id' => $branch1->id]);
            Service::create([...$service, 'branch_id' => $branch2->id]);
        }

        // =========================
        // EMPLOYEES
        // =========================

        // Branch 1 → 2 employees
        $employeesBranch1 = [
            Employee::create(['name' => 'Ana', 'branch_id' => $branch1->id]),
            Employee::create(['name' => 'Sofía', 'branch_id' => $branch1->id]),
        ];

        // Branch 2 → 3 employees
        $employeesBranch2 = [
            Employee::create(['name' => 'María', 'branch_id' => $branch2->id]),
            Employee::create(['name' => 'Lucía', 'branch_id' => $branch2->id]),
            Employee::create(['name' => 'Valeria', 'branch_id' => $branch2->id]),
        ];

        // =========================
        // SCHEDULES (Mon–Sat 9am–6pm, break 2–3pm)
        // =========================
        $allEmployees = array_merge($employeesBranch1, $employeesBranch2);

        foreach ($allEmployees as $employee) {
            for ($day = 1; $day <= 6; $day++) { // Monday to Saturday
                EmployeeSchedule::create([
                    'employee_id' => $employee->id,
                    'day_of_week' => $day,
                    'start_time' => '09:00:00',
                    'end_time' => '18:00:00',
                    'break_start' => '14:00:00',
                    'break_end' => '15:00:00',
                    'is_working_day' => true,
                ]);
            }

            // Sunday off
            EmployeeSchedule::create([
                'employee_id' => $employee->id,
                'day_of_week' => 0,
                'is_working_day' => false,
            ]);
        }
    }
}
