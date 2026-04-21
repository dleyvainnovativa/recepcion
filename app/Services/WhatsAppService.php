<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    public function sendMessage($to, $message)
    {
        $phoneId = config('services.whatsapp.phone_id');
        $token = config('services.whatsapp.token');

        $url = "https://graph.facebook.com/v18.0/{$phoneId}/messages";

        return Http::withToken($token)->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $message
            ]
        ]);
    }
}
