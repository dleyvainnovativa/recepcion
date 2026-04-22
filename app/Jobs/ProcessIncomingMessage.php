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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // public int $tries = 3;

    public function __construct(
        public readonly string $phone,
        public readonly string $message,
    ) {}

    public function handle(
        AIService           $ai,
        AvailabilityService $availability,
        ConversationService $conversationService,
    ): void {
        // ── 1. Load or create the conversation ──────────────────────────────
        $conversation = $conversationService->get($this->phone);
        $history      = $conversationService->getHistory($conversation);

        // ── 2. Load live business data once (branches, services, employees) ─
        $businessData = $this->loadBusinessData();

        // ── 3. Ask the AI to parse intent + extract entities ────────────────
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

        // ── 4. Merge extracted data with any data saved from prior turns ────
        //      (e.g. service was given two messages ago, now user adds date)
        $pending = $conversationService->getContextData($conversation, 'pending', []);
        $merged  = array_merge($pending, array_filter($data, fn($v) => $v !== null));

        // ── 5. Route by intent ───────────────────────────────────────────────
        $reply = match ($data['intent'] ?? 'unknown') {
            'greeting'              => $data['reply'],
            'create_appointment'    => $this->handleCreateAppointment($merged, $data['reply'], $conversation, $conversationService, $availability),
            'check_availability'    => $this->handleCheckAvailability($merged, $data['reply'], $availability),
            'cancel_appointment'    => $this->handleCancelAppointment($merged, $data['reply']),
            'reschedule_appointment' => $data['reply'], // extend as needed
            'ask_services'          => $data['reply'], // AI already used real DB data
            'ask_branches'          => $data['reply'],
            'ask_prices'            => $data['reply'],
            'ask_employees'         => $data['reply'],
            'general_question'      => $data['reply'],
            default                 => $data['reply'] ?? "¿En qué puedo ayudarte?",
        };

        // ── 6. Persist updated history ───────────────────────────────────────
        $conversationService->appendHistory($conversation, $this->message, $reply);

        // ── 7. Send reply ────────────────────────────────────────────────────
        $this->reply($reply);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Intent Handlers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Try to create an appointment.
     * If required data (service, datetime) is missing, save what we have and
     * return the AI's clarification question instead of attempting to book.
     */
    private function handleCreateAppointment(
        array               $merged,
        string              $aiReply,
        $conversation,
        ConversationService $conversationService,
        AvailabilityService $availability,
    ): string {
        $serviceName  = $merged['service']     ?? null;
        $datetime     = $merged['datetime']    ?? null;
        $clientName   = $merged['client_name'] ?? null;
        $branchName   = $merged['branch']      ?? null;
        $employeeName = $merged['employee']    ?? null;

        // ── Missing required fields → save progress, let AI ask ─────────────
        if (!$serviceName || !$datetime) {
            $conversationService->setContextData($conversation, array_filter([
                'service'     => $serviceName,
                'datetime'    => $datetime,
                'client_name' => $clientName,
                'branch'      => $branchName,
                'employee'    => $employeeName,
            ]));
            return $aiReply; // AI already asked the clarifying question
        }

        // ── Resolve service from DB ──────────────────────────────────────────
        $service = Service::where('name', 'like', "%{$serviceName}%")->first();
        if (!$service) {
            return "No encontré el servicio \"{$serviceName}\". ¿Puedes confirmar el nombre?";
        }

        // ── Resolve branch (use service's branch as fallback) ────────────────
        $branch = $branchName
            ? Branch::where('name', 'like', "%{$branchName}%")->first()
            : Branch::find($service->branch_id);

        if (!$branch) {
            return "No encontré la sucursal indicada. ¿Puedes especificarla?";
        }

        // ── Resolve employee (optional) ──────────────────────────────────────
        $employee = null;
        if ($employeeName) {
            $employee = Employee::where('branch_id', $branch->id)
                ->where('name', 'like', "%{$employeeName}%")
                ->where('active', 1)
                ->first();
        }

        // ── Parse datetime ───────────────────────────────────────────────────
        $startTime = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $datetime);
        $endTime   = $startTime->copy()->addMinutes($service->duration_minutes);

        // ── Check availability ───────────────────────────────────────────────
        $isAvailable = $availability->isSlotFree(
            branchId: $branch->id,
            serviceId: $service->id,
            employeeId: $employee?->id,
            start: $startTime,
            end: $endTime,
        );

        if (!$isAvailable) {
            $suggestions = $availability->suggestNearbySlots(
                branchId: $branch->id,
                serviceId: $service->id,
                employeeId: $employee?->id,
                around: $startTime,
                count: 3,
            );

            if ($suggestions->isEmpty()) {
                return "Lo siento, ese horario no está disponible y no encontré opciones cercanas. ¿Deseas intentar otro día?";
            }

            $options = $suggestions->map(fn($s) => $s->format('d/m/Y \a \l\a\s H:i'))->join(', ');
            return "Ese horario no está disponible. Tenemos espacio en: {$options}. ¿Cuál prefieres?";
        }

        // ── Create the appointment ───────────────────────────────────────────
        $appointment = Appointment::create([
            'client_name'  => $clientName ?? 'Cliente WhatsApp',
            'client_phone' => $this->phone,
            'branch_id'    => $branch->id,
            'service_id'   => $service->id,
            'employee_id'  => $employee?->id,
            'start_time'   => $startTime,
            'end_time'     => $endTime,
            'status'       => 'scheduled',
        ]);

        // ── Clear pending data now that the booking is done ──────────────────
        $conversationService->setContextData($conversation, []);

        $employeeText = $employee ? " con {$employee->name}" : '';
        $dateText     = $startTime->translatedFormat('l d \d\e F \a \l\a\s H:i');

        return "Cita confirmada para {$service->name}{$employeeText} el {$dateText} en {$branch->name}. "
            . "Tu número de cita es #{$appointment->id}. ¡Te esperamos!";
    }

    /**
     * Check availability for a given service/date without booking.
     */
    private function handleCheckAvailability(
        array               $merged,
        string              $aiReply,
        AvailabilityService $availability,
    ): string {
        $serviceName = $merged['service']  ?? null;
        $datetime    = $merged['datetime'] ?? null;

        if (!$serviceName || !$datetime) {
            return $aiReply; // AI will ask for missing info
        }

        $service = Service::where('name', 'like', "%{$serviceName}%")->first();
        if (!$service) {
            return "No encontré el servicio \"{$serviceName}\". ¿Puedes confirmar el nombre?";
        }

        $start = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $datetime);
        $end   = $start->copy()->addMinutes($service->duration_minutes);

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

    /**
     * Cancel an appointment by ID or by looking up the client's phone.
     */
    private function handleCancelAppointment(array $merged, string $aiReply): string
    {
        // Try to find upcoming appointment by phone number
        $appointment = Appointment::where('client_phone', $this->phone)
            ->where('status', 'scheduled')
            ->where('start_time', '>', now())
            ->orderBy('start_time')
            ->first();

        if (!$appointment) {
            return "No encontré citas activas registradas con este número. "
                . "Si crees que es un error, contáctanos directamente.";
        }

        // If multiple appointments exist you may want to list them; for now cancel the soonest
        $appointment->update(['status' => 'cancelled']);

        $dateText = $appointment->start_time->translatedFormat('l d \d\e F \a \l\a\s H:i');
        return "Tu cita #{$appointment->id} del {$dateText} ha sido cancelada. "
            . "Si deseas reagendar, con gusto te ayudo.";
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Load all relevant business data from the DB in one place.
     * This is passed to the AI so it can answer questions about services/prices/employees
     * using real information instead of hallucinating.
     */
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

        // Optionally notify the user
        // app(WhatsAppService::class)->sendMessage(
        //     $this->phone,
        //     "Tuve un problema al procesar tu solicitud. Por favor intenta de nuevo en un momento."
        // );
    }
}
