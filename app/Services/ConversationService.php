<?php

namespace App\Services;

use App\Models\Conversation;

class ConversationService
{
    public function get($phone)
    {
        return Conversation::firstOrCreate(
            ['phone' => $phone],
            [
                'context' => [],
                'current_step' => null
            ]
        );
    }

    public function merge($conversation, $newData,)
    {
        $current = $conversation->context ?? [];

        $merged = [
            'service'  => $newData['service']  ?: $current['service']  ?? null,
            'branch'   => $newData['branch']   ?: $current['branch']   ?? null,
            'employee' => $newData['employee'] ?: $current['employee'] ?? null,
            'datetime' => $newData['datetime'] ?: $current['datetime'] ?? null,
            'name'     => $newData['name']     ?: $current['name']     ?? null,
            'reply' => $newData['reply']
        ];

        $conversation->update([
            'context' => $merged
        ]);

        return $merged;
    }

    public function clear($conversation)
    {
        $conversation->update([
            'context' => [],
            'current_step' => null
        ]);
    }
}
