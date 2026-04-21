<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\ProcessIncomingMessage;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Log incoming message
        Log::info('WhatsApp Webhook:', $request->all());

        // Extract message (simplified)
        $message = $request->input('entry.0.changes.0.value.messages.0.text.body');
        $phone = $request->input('entry.0.changes.0.value.messages.0.from');

        if ($message && $phone) {
            ProcessIncomingMessage::dispatch($phone, $message);
        }

        return response()->json(['status' => 'ok']);
    }

    public function verify(Request $request)
    {
        $verifyToken = env('WHATSAPP_VERIFY_TOKEN');

        $mode = $request->input('hub.mode');
        $token = $request->input('hub.verify_token');
        $challenge = $request->input('hub.challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }
}
