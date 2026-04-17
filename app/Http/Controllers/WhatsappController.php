<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Kamus;
use Illuminate\Support\Facades\Http;

class WhatsappController extends Controller
{
    public function handle(Request $request)
    {
        $pesan = strtolower($request->input('message'));
        $sender = $request->input('sender');

        $data = Kamus::where('indonesia', 'like', "%$pesan%")->first();

        if ($data) {
            // Susun teks balasan untuk kedua dialek
            $replyText = "Terjemahan kata *$pesan*:\n\n";
            $replyText .= "🔸 Dialek A: " . $data->dialek_a . "\n";
            $replyText .= "🔸 Dialek O: " . $data->dialek_o;

            // 1. Kirim Teks
            $this->sendToFonnte($sender, $replyText);

            // 2. Kirim Audio Dialek A (jika ada)
            if ($data->audio_a) {
                $urlA = url('storage/audio/' . $data->audio_a);
                $this->sendToFonnte($sender, '', $urlA);
            }

            // 3. Kirim Audio Dialek O (jika ada)
            if ($data->audio_o) {
                $urlO = url('storage/audio/' . $data->audio_o);
                $this->sendToFonnte($sender, '', $urlO);
            }
        } else {
            $this->sendToFonnte($sender, "Maaf, kata '$pesan' belum tersedia di kamus.");
        }
    }

    // Helper function agar kode lebih bersih
    private function sendToFonnte($target, $message, $url = null)
    {
        $data = [
            'target' => $target,
            'message' => $message,
        ];

        if ($url) {
            $data['url'] = $url;
            $data['type'] = 'audio';
        }

        return Http::withHeaders([
            'Authorization' => 'TOKEN_FONNTE_MU',
        ])->post('https://api.fonnte.com/send', $data);
    }
}