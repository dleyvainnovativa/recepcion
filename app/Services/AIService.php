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

Extract:
- intent
- service
- datetime
- employee
- name

Return ONLY JSON.
If missing data, return null.
'
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
