<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AvailabilityService;


class AppointmentController extends Controller
{

    public function book(Request $request)
    {
        $service = \App\Models\Service::findOrFail($request->service_id);

        $start = \Carbon\Carbon::parse($request->datetime);
        $end = $start->copy()->addMinutes($service->duration_minutes);

        $appointment = \App\Models\Appointment::create([
            'client_name' => $request->client_name,
            'client_phone' => $request->client_phone,
            'branch_id' => $request->branch_id,
            'service_id' => $request->service_id,
            'employee_id' => $request->employee_id,
            'start_time' => $start,
            'end_time' => $end,
        ]);

        return response()->json($appointment);
    }

    public function cancel(Request $request)
    {
        $appointment = \App\Models\Appointment::findOrFail($request->appointment_id);

        $appointment->update([
            'status' => 'cancelled'
        ]);

        return response()->json(['status' => 'cancelled']);
    }
    public function reschedule(Request $request)
    {
        $appointment = \App\Models\Appointment::findOrFail($request->appointment_id);

        $service = $appointment->service;

        $start = \Carbon\Carbon::parse($request->datetime);
        $end = $start->copy()->addMinutes($service->duration_minutes);

        $appointment->update([
            'start_time' => $start,
            'end_time' => $end,
        ]);

        return response()->json($appointment);
    }

    public function checkAvailability(Request $request, AvailabilityService $availability)
    {
        $result = $availability->check(
            $request->branch_id,
            $request->service_id,
            $request->datetime,
            $request->employee_id
        );

        return response()->json($result);
    }
    public function getAvailableSlots(Request $request, AvailabilityService $availability)
    {
        $slots = $availability->getAvailableSlots(
            $request->branch_id,
            $request->service_id,
            $request->date,
            $request->employee_id
        );

        return response()->json($slots);
    }
}
