<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\AIController;
use App\Http\Controllers\Api\AppointmentController;

Route::get('/webhook/whatsapp', [WebhookController::class, 'verify']);

Route::post('/webhook/whatsapp', [WebhookController::class, 'handle']);

Route::post('/ai/parse', [AIController::class, 'parseMessage']);

Route::post('/appointments/check-availability', [AppointmentController::class, 'checkAvailability']);
Route::post('/appointments/book', [AppointmentController::class, 'book']);
Route::post('/appointments/cancel', [AppointmentController::class, 'cancel']);
Route::post('/appointments/reschedule', [AppointmentController::class, 'reschedule']);
Route::post('/appointments/slots', [AppointmentController::class, 'getAvailableSlots']);
