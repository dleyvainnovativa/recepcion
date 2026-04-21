<?php

namespace App\Services;

use OpenAI;

class AIService
{
    public function parse($message)
    {
        $client = OpenAI::client(config('services.openai.key'));

        $response = $client->chat()->create([
            'model' => 'gpt-4.1-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => '
You are an assistant for a beauty clinic.

Detect the user intent.

Possible intents:
- greeting
- create_appointment
- cancel_appointment
- reschedule_appointment

Extract:
- intent
- service
- datetime
- employee
- name

If the message is a greeting like "hola", "hi", return:
{
  "intent": "greeting",
  "service": null,
  "datetime": null,
  "employee": null,
  "name": null
}

Return ONLY JSON.'
                ],
                [
                    'role' => 'user',
                    'content' => $message
                ]
            ],
        ]);

        $content = $response->choices[0]->message->content;

        return json_decode($content, true);
    }
}
