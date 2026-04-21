<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function sendMessage($to, $message)
    {
        $phoneId = config('services.whatsapp.phone_id');
        $token = config('services.whatsapp.token');

        $url = "https://graph.facebook.com/v18.0/{$phoneId}/messages";
        Log::info('Reply Whatsapp Message', [$message, $to]);

        $send =  Http::withToken($token)->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $message
            ]
        ]);
        Log::info('Reply Whatsapp Response', [$send]);
        return $send;
    }
}
