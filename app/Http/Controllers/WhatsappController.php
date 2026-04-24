<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Kamus;
use Illuminate\Support\Facades\Http;

class WhatsappController extends Controller
{
    public function handle(Request $request)
    {
        $pesanOriginal = strtolower($request->input('message'));
        $sender = $request->input('sender');

        // Daftar kata yang ingin dibuang (Stopwords/Patterns)
        $patterns = [
            'apa bahasa lampungnya',
            'arti kata',
            'terjemahan',
            'apa itu',
            'tolong artikan',
            'bahasa lampung dari',
            '?', // hapus tanda tanya
        ];

        // Proses pembersihan: hapus kalimat tanya agar sisa kata intinya saja
        $keyword = str_replace($patterns, '', $pesanOriginal);
        $keyword = trim($keyword); // hapus spasi di awal/akhir

        // Cari berdasarkan keyword yang sudah bersih
        $data = Kamus::where('indonesia', 'like', "%$keyword%")->first();

        if ($data) {
            $replyText = "Terjemahan kata *$keyword*:\n\n";
            $replyText .= "🔸 Dialek A: " . $data->dialek_a . "\n";
            $replyText .= "🔸 Dialek O: " . $data->dialek_o;
            
            // 1. Kirim Teks Terjemahan Dulu
            $this->sendToFonnte($sender, $replyText);
            
            // 2. Kirim Audio Dialek A (Jika ada datanya di database)
            if (!empty($data->audio_a)) {
                $urlA = url('storage/audio/' . $data->audio_a);
                $this->sendToFonnte($sender, '', $urlA);
            }

            // 3. Kirim Audio Dialek O (Jika ada datanya di database)
            if (!empty($data->audio_o)) {
                $urlO = url('storage/audio/' . $data->audio_o);
                $this->sendToFonnte($sender, '', $urlO);
            }

        } else {
            $this->sendToFonnte($sender, "Maaf, kata '$keyword' belum tersedia.");
        }
    }

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
            'Authorization' => 'zAxcgAg9iGJMj4GSw23y',
        ])->post('https://api.fonnte.com/send', $data);
    }
}