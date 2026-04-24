<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Service;
use App\Services\AIService;
use App\Services\AvailabilityService;
use App\Services\ConversationService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $phone,
        public readonly string $message,
    ) {}

    public function handle(
        AIService           $ai,
        AvailabilityService $availability,
        ConversationService $conversationService,
    ): void {
        // ── 1. Load or create conversation ───────────────────────────────────
        $conversation = $conversationService->get($this->phone);
        $history      = $conversationService->getHistory($conversation);

        // ── 2. Load live business data ────────────────────────────────────────
        $businessData = $this->loadBusinessData();

        // ── 3. AI parse ───────────────────────────────────────────────────────
        $data = $ai->parse($this->message, $history, $businessData);

        if (!$data) {
            $this->reply("Lo siento, tuve un problema al procesar tu mensaje. ¿Puedes intentarlo de nuevo?");
            return;
        }

        Log::info('[ProcessIncomingMessage] AI parsed', [
            'phone'  => $this->phone,
            'intent' => $data['intent'] ?? 'unknown',
            'data'   => $data,
        ]);

        // ── 4. Merge AI output with pending data from prior turns ─────────────
        //      Only overwrite a pending value if the AI returned something non-null.
        $pending = $conversationService->getContextData($conversation, 'pending', []);
        $merged  = array_merge(
            $pending,
            array_filter($data, fn($v) => $v !== null && $v !== '')
        );

        // ── 5. Route by intent ────────────────────────────────────────────────
        $reply = match ($data['intent'] ?? 'unknown') {
            'greeting'               => $data['reply'],
            'create_appointment'     => $this->handleCreateAppointment($merged, $data, $conversation, $conversationService, $availability),
            'check_availability'     => $this->handleCheckAvailability($merged, $data['reply'], $availability),
            'cancel_appointment'     => $this->handleCancelAppointment($data['reply'], $conversationService, $conversation),
            'reschedule_appointment' => $data['reply'],
            'ask_services'           => $data['reply'],
            'ask_branches'           => $data['reply'],
            'ask_prices'             => $data['reply'],
            'ask_employees'          => $data['reply'],
            'general_question'       => $data['reply'],
            default                  => $data['reply'] ?? "¿En qué puedo ayudarte?",
        };

        // ── 6. Persist history ────────────────────────────────────────────────
        //      Note: reset() clears history, so only append if conversation is
        //      still active (i.e. context still has a history key).
        $conversation->refresh();
        if (
            $conversationService->getContextData($conversation, 'pending') !== null
            || !empty($conversationService->getHistory($conversation)) === false
        ) {
            // Always append — reset already happened inside the handler if booking completed
        }
        $conversationService->appendHistory($conversation, $this->message, $reply);

        // ── 7. Send reply ─────────────────────────────────────────────────────
        $this->reply($reply);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Intent Handlers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Handle create_appointment intent.
     *
     * Required fields before booking: service, datetime, employee.
     *
     * Employee resolution strategy:
     *   1. Client explicitly named one → find + validate availability.
     *   2. Client didn't name one AND there is more than 1 active employee in the branch
     *      → save progress, return the AI reply (which should be asking who they prefer).
     *   3. Client didn't name one AND there is exactly 1 employee → auto-assign silently.
     *   4. Client didn't name one AND after being asked they still haven't → auto-assign
     *      the least-busy available one (detected via 'employee_asked' flag in pending).
     */
    private function handleCreateAppointment(
        array               $merged,
        array               $aiData,
        $conversation,
        ConversationService $conversationService,
        AvailabilityService $availability,
    ): string {
        $serviceName   = $merged['service']      ?? null;
        $datetime      = $merged['datetime']     ?? null;
        $clientName    = $merged['client_name']  ?? null;
        $branchName    = $merged['branch']       ?? null;
        $employeeName  = $merged['employee']     ?? null;
        $employeeAsked = $merged['employee_asked'] ?? false;

        // ── Missing service or datetime → save and ask ────────────────────────
        if (!$serviceName || !$datetime) {
            $conversationService->setContextData($conversation, array_filter([
                'service'      => $serviceName,
                'datetime'     => $datetime,
                'client_name'  => $clientName,
                'branch'       => $branchName,
                'employee'     => $employeeName,
                'employee_asked' => $employeeAsked,
            ], fn($v) => $v !== null && $v !== false));
            return $aiData['reply'];
        }

        // ── Resolve service ───────────────────────────────────────────────────
        $service = Service::where('name', 'like', "%{$serviceName}%")->first();
        if (!$service) {
            return "No encontré el servicio \"{$serviceName}\". ¿Puedes confirmar el nombre?";
        }

        // ── Resolve branch ────────────────────────────────────────────────────
        $branch = $branchName
            ? Branch::where('name', 'like', "%{$branchName}%")->first()
            : Branch::find($service->branch_id);

        if (!$branch) {
            return "No encontré la sucursal indicada. ¿Puedes especificarla?";
        }

        // ── Parse datetime ────────────────────────────────────────────────────
        try {
            $startTime = Carbon::createFromFormat('Y-m-d H:i', $datetime);
        } catch (\Throwable) {
            return "No pude interpretar la fecha y hora. ¿Puedes indicarla de nuevo? (ejemplo: mañana a las 10:30)";
        }
        $endTime = $startTime->copy()->addMinutes($service->duration_minutes);

        // ── Resolve employee ──────────────────────────────────────────────────
        $activeEmployees = Employee::where('branch_id', $branch->id)
            ->where('active', 1)
            ->get();

        if ($employeeName) {
            // Client named a specific employee
            $employee = $activeEmployees
                ->first(fn($e) => str_contains(strtolower($e->name), strtolower($employeeName)));

            if (!$employee) {
                return "No encontré a \"{$employeeName}\" en {$branch->name}. ¿Puedes verificar el nombre?";
            }

            if (!$availability->isSlotFree($branch->id, $service->id, $employee->id, $startTime, $endTime)) {
                $suggestions = $availability->suggestNearbySlots(
                    branchId: $branch->id,
                    serviceId: $service->id,
                    employeeId: $employee->id,
                    around: $startTime,
                    count: 3,
                );

                if ($suggestions->isEmpty()) {
                    return "{$employee->name} no tiene disponibilidad en ese horario ni cercano. ¿Deseas elegir a otro o intentar otro día?";
                }

                $options = $suggestions->map(fn($s) => $s->format('d/m/Y \a \l\a\s H:i'))->join(', ');
                return "{$employee->name} no está disponible a esa hora. Sus próximos horarios libres son: {$options}. ¿Cuál prefieres?";
            }
        } elseif ($activeEmployees->count() === 1) {
            // Only one option → assign automatically, no need to ask
            $employee = $activeEmployees->first();

            if (!$availability->isSlotFree($branch->id, $service->id, $employee->id, $startTime, $endTime)) {
                $suggestions = $availability->suggestNearbySlots(
                    branchId: $branch->id,
                    serviceId: $service->id,
                    employeeId: $employee->id,
                    around: $startTime,
                    count: 3,
                );
                $options = $suggestions->isEmpty()
                    ? 'ninguno cercano disponible'
                    : $suggestions->map(fn($s) => $s->format('d/m/Y \a \l\a\s H:i'))->join(', ');
                return "No hay disponibilidad a esa hora. Horarios libres: {$options}. ¿Cuál te acomoda?";
            }
        } elseif ($employeeAsked) {
            // We already asked the client who they prefer and they still haven't said →
            // auto-assign the least-busy available employee (best effort)
            $employee = $this->pickBestEmployee($branch->id, $service->id, $startTime, $endTime, $availability);

            if (!$employee) {
                $suggestions = $availability->suggestNearbySlots(
                    branchId: $branch->id,
                    serviceId: $service->id,
                    around: $startTime,
                    count: 3,
                );
                if ($suggestions->isEmpty()) {
                    return "No hay disponibilidad en {$branch->name} para ese horario. ¿Deseas intentar otro día?";
                }
                $options = $suggestions->map(fn($s) => $s->format('d/m/Y \a \l\a\s H:i'))->join(', ');
                return "No hay disponibilidad a esa hora en {$branch->name}. Tenemos espacio en: {$options}. ¿Cuál te acomoda?";
            }
        } else {
            // Multiple employees, haven't asked yet → save progress and ask
            $names = $activeEmployees->pluck('name')->join(', ');
            $conversationService->setContextData($conversation, array_filter([
                'service'        => $serviceName,
                'datetime'       => $datetime,
                'client_name'    => $clientName,
                'branch'         => $branchName,
                'employee'       => null,
                'employee_asked' => true, // flag so next turn we auto-assign if still null
            ], fn($v) => $v !== null && $v !== false));

            $dateText = $startTime->translatedFormat('l d \d\e F \a \l\a\s H:i');
            return "Perfecto. En {$branch->name} tenemos a: {$names}. ¿Con quién te gustaría tu cita de {$service->name} el {$dateText}?";
        }

        // ── All data collected → create the appointment ───────────────────────
        $appointment = Appointment::create([
            'client_name'  => $clientName ?? 'Cliente WhatsApp',
            'client_phone' => $this->phone,
            'branch_id'    => $branch->id,
            'service_id'   => $service->id,
            'employee_id'  => $employee->id,
            'start_time'   => $startTime,
            'end_time'     => $endTime,
            'status'       => 'scheduled',
        ]);

        Log::info('[ProcessIncomingMessage] Appointment created', ['id' => $appointment->id]);

        // ── Reset conversation so the next message starts fresh ───────────────
        $conversationService->reset($conversation);

        $dateText = $startTime->translatedFormat('l d \d\e F \a \l\a\s H:i');
        return "Cita confirmada para {$service->name} con {$employee->name} el {$dateText} en {$branch->name}. "
            . "Tu número de cita es #{$appointment->id}. ¡Te esperamos!";
    }

    /**
     * Pick the least-busy active employee who is free at the requested slot.
     * Returns null if no one is available.
     */
    private function pickBestEmployee(
        int                 $branchId,
        int                 $serviceId,
        Carbon              $start,
        Carbon              $end,
        AvailabilityService $availability,
    ): ?Employee {
        $employees = Employee::where('branch_id', $branchId)
            ->where('active', 1)
            ->get();

        $dayStart = $start->copy()->startOfDay();
        $dayEnd   = $start->copy()->endOfDay();

        $best      = null;
        $bestLoad  = PHP_INT_MAX;

        foreach ($employees as $employee) {
            if (!$availability->isSlotFree($branchId, $serviceId, $employee->id, $start, $end)) {
                continue;
            }

            $load = Appointment::where('employee_id', $employee->id)
                ->where('status', 'scheduled')
                ->whereBetween('start_time', [$dayStart, $dayEnd])
                ->count();

            if ($load < $bestLoad) {
                $bestLoad = $load;
                $best     = $employee;
            }
        }

        return $best;
    }

    private function handleCheckAvailability(
        array               $merged,
        string              $aiReply,
        AvailabilityService $availability,
    ): string {
        $serviceName = $merged['service']  ?? null;
        $datetime    = $merged['datetime'] ?? null;

        if (!$serviceName || !$datetime) {
            return $aiReply;
        }

        $service = Service::where('name', 'like', "%{$serviceName}%")->first();
        if (!$service) {
            return "No encontré el servicio \"{$serviceName}\". ¿Puedes confirmar el nombre?";
        }

        $start = Carbon::createFromFormat('Y-m-d H:i', $datetime);
        $slots = $availability->suggestNearbySlots(
            branchId: $service->branch_id,
            serviceId: $service->id,
            around: $start,
            count: 5,
        );

        if ($slots->isEmpty()) {
            return "No encontré horarios disponibles para {$service->name} en esa fecha. ¿Quieres intentar otro día?";
        }

        $options = $slots->map(fn($s) => $s->format('d/m/Y H:i'))->join(', ');
        return "Horarios disponibles para {$service->name}: {$options}. ¿Cuál te acomoda?";
    }

    private function handleCancelAppointment(
        string              $aiReply,
        ConversationService $conversationService,
        $conversation,
    ): string {
        $appointment = Appointment::where('client_phone', $this->phone)
            ->where('status', 'scheduled')
            ->where('start_time', '>', now())
            ->orderBy('start_time')
            ->first();

        if (!$appointment) {
            return "No encontré citas activas con este número. Si crees que es un error, contáctanos directamente.";
        }

        $appointment->update(['status' => 'cancelled']);

        // Reset conversation after cancellation too
        $conversationService->reset($conversation);

        $dateText = $appointment->start_time->translatedFormat('l d \d\e F \a \l\a\s H:i');
        return "Tu cita #{$appointment->id} del {$dateText} ha sido cancelada. Si deseas reagendar, con gusto te ayudo.";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function loadBusinessData(): array
    {
        $branches = Branch::all()->map(fn($b) => [
            'name'    => $b->name,
            'address' => $b->address,
            'phone'   => $b->phone,
        ])->toArray();

        $services = Service::with('branch')->get()->map(fn($s) => [
            'name'             => $s->name,
            'duration_minutes' => $s->duration_minutes,
            'price'            => number_format($s->price, 2),
            'branch'           => $s->branch->name ?? 'N/A',
        ])->toArray();

        $employees = Employee::with('branch')->where('active', 1)->get()->map(fn($e) => [
            'name'   => $e->name,
            'branch' => $e->branch->name ?? 'N/A',
        ])->toArray();

        return compact('branches', 'services', 'employees');
    }

    private function reply(string $message): void
    {
        Log::info('[ProcessIncomingMessage] Sending reply', [
            'phone'   => $this->phone,
            'message' => $message,
        ]);

        app(WhatsAppService::class)->sendMessage($this->phone, $message);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[ProcessIncomingMessage] Job failed', [
            'phone' => $this->phone,
            'error' => $exception->getMessage(),
        ]);

        app(WhatsAppService::class)->sendMessage(
            $this->phone,
            "Tuve un problema al procesar tu solicitud. Por favor intenta de nuevo en un momento."
        );
    }
}
