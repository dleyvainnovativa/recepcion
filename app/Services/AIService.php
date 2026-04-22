<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use OpenAI;

class AIService
{
    /**
     * Parse an incoming message using OpenAI, injecting conversation history
     * so the model has memory of the full exchange.
     *
     * @param  string  $message        The raw user message.
     * @param  array   $history        Prior messages [['role'=>'user','content'=>'...'], ...].
     * @param  array   $businessData   Live data from DB (services, employees, branches).
     * @return array|null              Decoded JSON from the model, or null on failure.
     */
    public function parse(string $message, array $history = [], array $businessData = []): ?array
    {
        $client = OpenAI::client(config('services.openai.key'));

        $today    = now()->format('Y-m-d');
        $dayName  = now()->translatedFormat('l'); // e.g. "martes" with Spanish locale
        $time     = now()->format('H:i');

        // Build a readable summary of business data to inject into the system prompt
        $businessContext = $this->buildBusinessContext($businessData);

        $systemPrompt = <<<PROMPT
Hoy es {$today} ({$dayName}), hora actual: {$time}.

Eres la recepcionista virtual de una clínica de belleza. Eres profesional, amable y concisa.
Respondes siempre en español y NUNCA en inglés.
No uses emojis.

---

INFORMACIÓN DEL NEGOCIO (usa esto para responder preguntas):
{$businessContext}

---

INTENCIONES QUE PUEDES DETECTAR:
- greeting
- create_appointment
- cancel_appointment
- reschedule_appointment
- check_availability
- ask_services
- ask_branches
- ask_prices
- ask_employees
- general_question
- unknown

---

DATOS A EXTRAER (cuando aplique):
- intent        → la intención del mensaje
- service       → nombre del servicio mencionado (o null)
- branch        → nombre de la sucursal mencionada (o null)
- employee      → nombre del empleado mencionado (o null)
- datetime      → fecha y hora en formato YYYY-MM-DD HH:MM (o null)
- client_name   → nombre del cliente si lo menciona (o null)

---

REGLAS DE FECHA Y HORA:
- Usa el año actual basado en la fecha de hoy.
- Convierte expresiones como "mañana", "hoy", "el lunes" a fechas reales.
- "en la mañana" → 09:00 | "en la tarde" → 15:00 | "en la noche" → 20:00
- Si sólo se menciona fecha sin hora, usa 09:00.
- Si la fecha/hora es ambigua, devuelve null y pide aclaración en "reply".

---

REGLAS DE COMPORTAMIENTO:
- NUNCA asumas disponibilidad. El sistema la verifica.
- Si el usuario quiere agendar, extrae: service, branch, employee (opcional), datetime, client_name.
  Si falta algún dato obligatorio (service, datetime), pregunta por él en "reply".
- Si el usuario pregunta por servicios o precios, usa la información del negocio.
- Si el usuario quiere cancelar o reprogramar, pide su número de cita o teléfono si no lo tienes.
- Mantén el contexto de la conversación: si ya se mencionó un servicio antes, no lo vuelvas a pedir.

---

FORMATO DE RESPUESTA (JSON estricto, sin markdown, sin texto extra):

{
  "intent": "",
  "service": null,
  "branch": null,
  "employee": null,
  "datetime": null,
  "client_name": null,
  "reply": ""
}
PROMPT;

        // Build the messages array: system + full history + current user message
        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $entry) {
            $messages[] = ['role' => $entry['role'], 'content' => $entry['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        try {
            $response = $client->chat()->create([
                'model'       => 'gpt-4.1-mini',
                'temperature' => 0.3, // Lower = more predictable JSON output
                'messages'    => $messages,
            ]);

            $content = $response->choices[0]->message->content ?? '';

            Log::info('[AIService] Raw response', ['content' => $content]);

            // Strip accidental markdown fences before decoding
            $clean = preg_replace('/^```json|^```|```$/m', '', trim($content));

            $decoded = json_decode($clean, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('[AIService] JSON decode failed', ['raw' => $content]);
                return null;
            }

            return $decoded;
        } catch (\Throwable $e) {
            Log::error('[AIService] API call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build a human-readable block of business data to embed in the system prompt.
     * This keeps the AI grounded in real DB data without it hallucinating services/prices.
     */
    private function buildBusinessContext(array $businessData): string
    {
        $lines = [];

        if (!empty($businessData['branches'])) {
            $lines[] = "SUCURSALES:";
            foreach ($businessData['branches'] as $b) {
                $lines[] = "  - {$b['name']}" . ($b['address'] ? " | {$b['address']}" : '') . ($b['phone'] ? " | Tel: {$b['phone']}" : '');
            }
        }

        if (!empty($businessData['services'])) {
            $lines[] = "\nSERVICIOS Y PRECIOS:";
            foreach ($businessData['services'] as $s) {
                $lines[] = "  - {$s['name']} | Duración: {$s['duration_minutes']} min | Precio: \${$s['price']} | Sucursal: {$s['branch']}";
            }
        }

        if (!empty($businessData['employees'])) {
            $lines[] = "\nEMPLEADOS ACTIVOS:";
            foreach ($businessData['employees'] as $e) {
                $lines[] = "  - {$e['name']} | Sucursal: {$e['branch']}";
            }
        }

        return $lines ? implode("\n", $lines) : "No hay información de negocio disponible.";
    }
}
