<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

class ConversationService
{
    /**
     * Get the conversation record for a phone number, creating it if needed.
     */
    public function get(string $phone): Conversation
    {
        return Conversation::firstOrCreate(
            ['phone' => $phone],
            ['current_step' => null, 'context' => json_encode(['history' => []])]
        );
    }

    /**
     * Return the full message history ready to be injected into the OpenAI messages array.
     * Each entry is ['role' => 'user'|'assistant', 'content' => '...']
     */
    public function getHistory(Conversation $conversation): array
    {
        Log::info('Conversation Context:', [$conversation->context]);
        $context = json_decode($conversation->context, true);
        return $context['history'] ?? [];
    }

    /**
     * Append a user message and the assistant reply to the persistent history.
     * Keeps only the last $maxPairs exchanges to avoid ballooning token usage.
     */
    public function appendHistory(Conversation $conversation, string $userMessage, string $assistantReply, int $maxPairs = 10): void
    {
        $context = json_decode($conversation->context, true) ?? [];
        $history = $context['history'] ?? [];

        $history[] = ['role' => 'user',      'content' => $userMessage];
        $history[] = ['role' => 'assistant', 'content' => $assistantReply];

        // Keep only the last $maxPairs * 2 messages (user + assistant per pair)
        $history = array_slice($history, - ($maxPairs * 2));

        $context['history'] = $history;
        $conversation->context = json_encode($context);
        $conversation->save();
    }

    /**
     * Store arbitrary key/value data in the conversation context (e.g. pending booking data).
     */
    public function setContextData(Conversation $conversation, array $data): void
    {
        $context = json_decode($conversation->context, true) ?? [];
        foreach ($data as $key => $value) {
            $context[$key] = $value;
        }
        $conversation->context = json_encode($context);
        $conversation->save();
    }

    /**
     * Read a specific key from the conversation context.
     */
    public function getContextData(Conversation $conversation, string $key, mixed $default = null): mixed
    {
        $context = json_decode($conversation->context, true) ?? [];
        return $context[$key] ?? $default;
    }

    /**
     * Clear all conversation state (e.g. after a booking is completed or cancelled).
     */
    public function reset(Conversation $conversation): void
    {
        $conversation->current_step = null;
        $conversation->context = json_encode(['history' => []]);
        $conversation->save();
    }
}
