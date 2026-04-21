<?php

namespace App\Jobs;

use App\Services\AIService;
use App\Services\AvailabilityService;
use App\Models\Service;
use App\Models\Employee;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable; // 👈 IMPORTANT
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\WhatsAppService;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $phone;
    public $message;

    public function __construct($phone, $message)
    {
        $this->phone = $phone;
        $this->message = $message;
    }

    public function handle(AIService $ai, AvailabilityService $availability)
    {
        Log::info('JOB START', [$this->phone, $this->message]);

        $message = strtolower(trim($this->message));

        if (in_array($message, ['hola', 'hi', 'hello'])) {
            return $this->reply("¡Hola! 😊 ¿Qué servicio deseas agendar?");
        }

        $data = $ai->parse($this->message);

        Log::info('JOB DATA', [$data]);


        if (!$data) {
            return $this->reply("No entendí tu mensaje 😅");
        }

        switch ($data['intent']) {

            case 'greeting':
                return $this->reply("¡Hola! 😊 ¿Qué servicio deseas agendar?");

            case 'create_appointment':
                return $this->handleBooking($data, $availability);

            case 'cancel_appointment':
                return $this->reply("Claro 😊 ¿Cuál cita deseas cancelar?");

            case 'reschedule_appointment':
                return $this->reply("Perfecto 👍 ¿Para qué fecha deseas cambiar tu cita?");

            default:
                return $this->reply("¿En qué puedo ayudarte? 😊");
        }
    }

    private function handleBooking($data, $availability)
    {
        // Validate required data
        if (!$data['service']) {
            return $this->reply("¿Qué servicio deseas?");
        }

        if (!$data['datetime']) {
            return $this->reply("¿Para qué día y hora?");
        }

        // Find service (basic match)
        $service = Service::where('name', 'like', "%{$data['service']}%")->first();

        if (!$service) {
            return $this->reply("No encontré ese servicio 😅");
        }

        // Find employee (optional)
        $employeeId = null;

        if (!empty($data['employee'])) {
            $employee = Employee::where('name', 'like', "%{$data['employee']}%")->first();
            if ($employee) {
                $employeeId = $employee->id;
            }
        }

        // Check availability
        $result = $availability->check(
            $service->branch_id,
            $service->id,
            $data['datetime'],
            $employeeId
        );

        if (!$result['available']) {

            // Suggest slots
            $slots = $availability->getAvailableSlots(
                $service->branch_id,
                $service->id,
                $data['datetime']
            );

            if (empty($slots)) {
                return $this->reply("No tengo horarios disponibles ese día 😢");
            }

            $text = "No está disponible 😢\nTe ofrezco:\n";

            foreach (array_slice($slots, 0, 5) as $slot) {
                $text .= "- $slot\n";
            }

            return $this->reply($text);
        }

        // Book appointment
        $appointment = \App\Models\Appointment::create([
            'client_name' => $data['name'] ?? 'Cliente',
            'client_phone' => $this->phone,
            'branch_id' => $service->branch_id,
            'service_id' => $service->id,
            'employee_id' => $result['employee_id'] ?? $employeeId,
            'start_time' => $data['datetime'],
            'end_time' => now()->parse($data['datetime'])->addMinutes($service->duration_minutes),
        ]);

        return $this->reply("¡Listo! 💖 Tu cita está confirmada.");
    }


    private function reply($message)
    {
        Log::info('Reply Message', [$message]);

        app(WhatsAppService::class)->sendMessage($this->phone, $message);
    }
}
