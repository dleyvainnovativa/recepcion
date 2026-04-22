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

        $today   = now()->format('Y-m-d');
        $dayName = now()->translatedFormat('l');
        $time    = now()->format('H:i');

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

INSTRUCCIÓN CRÍTICA — FORMATO DE RESPUESTA:
Debes responder ÚNICAMENTE con un objeto JSON válido.
No escribas ningún texto antes ni después del JSON.
No uses bloques de código markdown (sin ```, sin ```json).
El campo "reply" contiene tu respuesta en lenguaje natural para el usuario.

Estructura exacta requerida:
{"intent":"","service":null,"branch":null,"employee":null,"datetime":null,"client_name":null,"reply":""}
PROMPT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $entry) {
            $messages[] = ['role' => $entry['role'], 'content' => $entry['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        try {
            $response = $client->chat()->create([
                'model'           => 'gpt-4.1-mini',
                'temperature'     => 0.2,
                'response_format' => ['type' => 'json_object'], // Enforce JSON at API level
                'messages'        => $messages,
            ]);

            $content = $response->choices[0]->message->content ?? '';

            Log::info('[AIService] Raw response', ['content' => $content]);

            $decoded = $this->decodeJson($content);

            if ($decoded === null) {
                Log::error('[AIService] JSON decode failed', ['raw' => $content]);
                return null;
            }

            // Ensure all expected keys exist so callers never get undefined index errors
            return array_merge([
                'intent'      => 'unknown',
                'service'     => null,
                'branch'      => null,
                'employee'    => null,
                'datetime'    => null,
                'client_name' => null,
                'reply'       => '',
            ], $decoded);
        } catch (\Throwable $e) {
            Log::error('[AIService] API call failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Attempt to decode a JSON string using three strategies in order:
     *  1. Direct decode (happy path — response_format: json_object should guarantee this).
     *  2. Strip markdown fences and retry.
     *  3. Extract the first {...} block found anywhere in the string and retry.
     */
    private function decodeJson(string $content): ?array
    {
        $content = trim($content);

        // Strategy 1: direct decode
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Strategy 2: strip markdown fences
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $clean = preg_replace('/\s*```$/m', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Strategy 3: extract the first { ... } block from anywhere in the string
        if (preg_match('/\{.*\}/s', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Build a human-readable block of business data to embed in the system prompt.
     */
    private function buildBusinessContext(array $businessData): string
    {
        $lines = [];

        if (!empty($businessData['branches'])) {
            $lines[] = "SUCURSALES:";
            foreach ($businessData['branches'] as $b) {
                $lines[] = "  - {$b['name']}"
                    . ($b['address'] ? " | {$b['address']}" : '')
                    . ($b['phone']   ? " | Tel: {$b['phone']}" : '');
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
