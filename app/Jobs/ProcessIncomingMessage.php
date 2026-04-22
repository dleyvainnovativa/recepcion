<?php

namespace App\Jobs;

use App\Models\Branch;
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

        $message = strtolower(trim($this->message));

        if (in_array($message, ['hola', 'hi', 'hello'])) {
            return $this->reply("¡Hola! ¿Qué servicio deseas agendar?");
        }

        $data = $ai->parse($this->message);

        Log::info('AI Parse', [$data]);

        if (!$data) {
            return $this->reply("No entendí tu mensaje 😅");
        }

        switch ($data['intent']) {

            case 'greeting':
                return $this->reply($data['reply'] ?? "Hola, ¿en qué puedo ayudarte?");

            case 'create_appointment':
                return $this->handleBooking($data, $availability);

            case 'cancel_appointment':
                return $this->reply($data['reply'] ?? "Claro, ¿qué cita deseas cancelar?");

            case 'reschedule_appointment':
                return $this->reply($data['reply'] ?? "Perfecto, dime la nueva fecha.");
            case 'ask_services':
                return $this->handleServices();

            case 'ask_prices':
                return $this->handlePrices($data);

            case 'ask_employees':
                return $this->handleEmployees($data);

            default:
                return $this->reply($data['reply'] ?? "¿En qué puedo ayudarte?");
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

    private function handlePrices($data)
    {
        if (empty($data['service'])) {
            return $this->reply("¿De qué servicio te gustaría conocer el precio?");
        }

        $service = Service::where('name', 'like', "%{$data['service']}%")->first();

        if (!$service) {
            return $this->reply("No encontré ese servicio.");
        }

        $text = "El servicio {$service->name} tiene un costo de {$service->price}.";

        if (!empty($service->duration_minutes)) {
            $text .= " Duración aproximada: {$service->duration_minutes} minutos.";
        }

        return $this->reply($text);
    }

    private function handleEmployees($data)
    {
        $query = Employee::query();

        // Filter by branch if provided
        if (!empty($data['branch'])) {
            $branch = Branch::where('name', 'like', "%{$data['branch']}%")->first();

            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        $employees = $query->get();

        if ($employees->isEmpty()) {
            return $this->reply("No tengo empleados disponibles en este momento.");
        }

        $text = "Estos son nuestros especialistas:\n";

        foreach ($employees as $employee) {
            $text .= "- {$employee->name}\n";
        }

        return $this->reply($text);
    }

    private function handleServices($data = null)
    {
        $query = Service::query();

        // If user mentioned branch
        if (!empty($data['branch'])) {
            $branch = Branch::where('name', 'like', "%{$data['branch']}%")->first();

            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        $services = $query->get();

        if ($services->isEmpty()) {
            return $this->reply("No tengo servicios disponibles en este momento.");
        }

        $text = "Estos son los servicios disponibles:\n";

        foreach ($services as $service) {
            $text .= "- {$service->name} ({$service->duration_minutes} min)\n";
        }

        return $this->reply($text);
    }


    private function reply($message)
    {
        Log::info('Reply Message', [$message]);

        app(WhatsAppService::class)->sendMessage($this->phone, $message);
    }
}
