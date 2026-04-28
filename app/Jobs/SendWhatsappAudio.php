<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class SendWhatsappAudio implements ShouldQueue
{
    use Queueable;

    public $target;
    public $audioPath;
    public $tries = 3;

    public function __construct($target, $audioPath)
    {
        $this->target = $target;
        $this->audioPath = $audioPath;
    }

    public function handle(): void
    {
        // PENTING: Gunakan config() bukan env() di dalam queue job!
        $apiKey = config('services.ngirimwa.api_key');
        $baseUrl = config('services.ngirimwa.base_url');
        $ngrokUrl = trim(config('services.ngirimwa.ngrok_url')) ?: rtrim(config('app.url'), '/');

        if (empty($apiKey) || empty($baseUrl)) {
            \Log::error('SendWhatsappAudio: API key atau base URL kosong!', [
                'api_key_set' => !empty($apiKey),
                'base_url' => $baseUrl,
            ]);
            return;
        }

        $filename = basename($this->audioPath);

        // Encode setiap segmen path secara manual untuk handle spasi dengan benar
        // rawurlencode mengubah spasi menjadi %20 (yang benar untuk URL path)
        $encodedFilename = rawurlencode($filename);
        $audioUrl = $ngrokUrl . '/storage/audio/' . $encodedFilename;

        \Log::info('SendWhatsappAudio Job Start', [
            'to' => $this->target,
            'original_filename' => $filename,
            'media_url' => $audioUrl,
        ]);

        try {
            $response = Http::withOptions(['verify' => false])
                ->withHeaders([
                    'x-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ])->post($baseUrl . '/messages/send', [
                    'to' => $this->target,
                    'media' => $audioUrl,
                    'media_type' => 'audio',
                ]);

            \Log::info('SendWhatsappAudio Job Complete', [
                'to' => $this->target,
                'filename' => $filename,
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            // Kalau response bukan 200, throw exception agar job di-retry
            if ($response->status() !== 200) {
                throw new \Exception('ngirimWA Audio API returned status: ' . $response->status() . ' - ' . $response->body());
            }
        } catch (\Exception $e) {
            \Log::error('SendWhatsappAudio Job Failed', [
                'to' => $this->target,
                'filename' => $filename,
                'media_url' => $audioUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
