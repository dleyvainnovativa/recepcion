<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AIService;

class AIController extends Controller
{
    public function parseMessage(Request $request, AIService $ai)
    {
        $message = $request->input('message');

        if (!$message) {
            return response()->json([
                'error' => 'Message is required'
            ], 400);
        }

        $data = $ai->parse($message);

        return response()->json($data);
    }
}
