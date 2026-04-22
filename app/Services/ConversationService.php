<?php

namespace App\Services;

use App\Models\Conversation;

class ConversationService
{
    /**
     * Get the conversation record for a phone number, creating it if needed.
     */
    public function get(string $phone): Conversation
    {
        return Conversation::firstOrCreate(
            ['phone' => $phone],
            ['current_step' => null, 'context' => ['history' => []]]
        );
    }

    /**
     * Return the full message history ready to be injected into the OpenAI messages array.
     * Each entry is ['role' => 'user'|'assistant', 'content' => '...']
     */
    public function getHistory(Conversation $conversation): array
    {
        return $this->context($conversation)['history'] ?? [];
    }

    /**
     * Append a user message and the assistant reply to the persistent history.
     * Keeps only the last $maxPairs exchanges to avoid ballooning token usage.
     */
    public function appendHistory(Conversation $conversation, string $userMessage, string $assistantReply, int $maxPairs = 10): void
    {
        $context = $this->context($conversation);
        $history = $context['history'] ?? [];

        $history[] = ['role' => 'user',      'content' => $userMessage];
        $history[] = ['role' => 'assistant', 'content' => $assistantReply];

        $context['history'] = array_slice($history, - ($maxPairs * 2));

        $conversation->context = $context;
        $conversation->save();
    }

    /**
     * Store arbitrary key/value data in the conversation context (e.g. pending booking data).
     */
    public function setContextData(Conversation $conversation, array $data): void
    {
        $conversation->context = array_merge($this->context($conversation), $data);
        $conversation->save();
    }

    /**
     * Read a specific key from the conversation context.
     */
    public function getContextData(Conversation $conversation, string $key, mixed $default = null): mixed
    {
        return $this->context($conversation)[$key] ?? $default;
    }

    /**
     * Clear all conversation state (e.g. after a booking is completed or cancelled).
     */
    public function reset(Conversation $conversation): void
    {
        $conversation->current_step = null;
        $conversation->context      = ['history' => []];
        $conversation->save();
    }

    /**
     * Always return the context as a plain PHP array, regardless of whether
     * Eloquent's cast has fired or the raw JSON string came back instead.
     *
     * This handles two edge cases:
     *  1. firstOrCreate() can return a partially hydrated model where the cast
     *     hasn't run yet and context is still the raw JSON string.
     *  2. Old rows that were stored before the cast was added to the model.
     */
    private function context(Conversation $conversation): array
    {
        $raw = $conversation->context;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
