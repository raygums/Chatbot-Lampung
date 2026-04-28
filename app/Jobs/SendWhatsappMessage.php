<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class SendWhatsappMessage implements ShouldQueue
{
    use Queueable;

    public $target;
    public $message;
    public $tries = 3;

    public function __construct($target, $message)
    {
        $this->target = $target;
        $this->message = $message;
    }

    public function handle(): void
    {
        // PENTING: Gunakan config() bukan env() di dalam queue job!
        // env() bisa return null saat config sudah di-cache
        $apiKey = config('services.ngirimwa.api_key');
        $baseUrl = config('services.ngirimwa.base_url');

        if (empty($apiKey) || empty($baseUrl)) {
            \Log::error('SendWhatsappMessage: API key atau base URL kosong!', [
                'api_key_set' => !empty($apiKey),
                'base_url' => $baseUrl,
            ]);
            return;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post($baseUrl . '/messages/send', [
                'to' => $this->target,
                'message' => $this->message,
            ]);

            \Log::info('SendWhatsappMessage Job Complete', [
                'to' => $this->target,
                'message_preview' => substr($this->message, 0, 50),
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            // Kalau response bukan 200, throw exception agar job di-retry
            if ($response->status() !== 200) {
                throw new \Exception('ngirimWA API returned status: ' . $response->status() . ' - ' . $response->body());
            }
        } catch (\Exception $e) {
            \Log::error('SendWhatsappMessage Job Failed', [
                'to' => $this->target,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
