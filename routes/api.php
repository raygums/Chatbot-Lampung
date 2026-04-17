<?php

use App\Http\Controllers\WhatsappController;

Route::post('/webhook', [WhatsappController::class, 'handle']);
