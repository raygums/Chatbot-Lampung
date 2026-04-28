<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Kamus;
use App\Jobs\SendWhatsappAudio;
use App\Jobs\SendWhatsappMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class WhatsappController extends Controller
{
    public function handle(Request $request)
    {
        // Log full request untuk debugging
        \Log::info('Webhook RAW request', [
            'all_input' => $request->all(),
        ]);

        // Parse request dari NgirimWA
        $pesanOriginal = $request->input('message.content') ?? $request->input('message') ?? '';
        $sender = $request->input('message.from') ?? $request->input('sender') ?? '';

        // Convert ke string dan lowercase
        if (is_array($pesanOriginal)) {
            $pesanOriginal = $pesanOriginal[0] ?? '';
        }
        $pesanRaw = trim((string)$pesanOriginal);
        $pesan = strtolower($pesanRaw);

        \Log::info('Webhook processed', [
            'sender' => $sender,
            'message' => substr($pesan, 0, 80),
        ]);

        // Kalau sender atau pesan kosong, skip
        if (empty($sender) || empty($pesan)) {
            \Log::warning('Webhook skipped: sender or message empty');
            return response()->json(['status' => 'error', 'message' => 'Empty sender or message'], 200);
        }

        // --- Duplicate check ---
        $webhookId = $request->input('id') ?? md5($sender . $pesan . now()->timestamp);
        $messageHash = md5($sender . $pesan);

        $alreadyProcessed = DB::table('webhook_logs')
            ->where('sender', $sender)
            ->where('message_hash', $messageHash)
            ->where('created_at', '>', now()->subMinutes(2))
            ->exists();

        if ($alreadyProcessed) {
            \Log::info('Webhook duplicate detected, skipping');
            return response()->json(['status' => 'success', 'message' => 'Duplicate message skipped'], 200);
        }

        try {
            DB::table('webhook_logs')->insertOrIgnore([
                'sender' => $sender,
                'message_hash' => $messageHash,
                'webhook_id' => $webhookId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to log webhook', ['error' => $e->getMessage()]);
        }

        // ========================================
        // PROSES PESAN — Deteksi intent pengguna
        // ========================================

        // 0. Cek apakah user baru (pertama kali chat)
        $isFirstTime = !DB::table('webhook_logs')
            ->where('sender', $sender)
            ->where('created_at', '<', now()->subSeconds(5)) // exclude pesan ini sendiri
            ->exists();

        if ($isFirstTime) {
            // Kirim sapaan selamat datang, lalu lanjut proses pesan
            dispatch(new SendWhatsappMessage($sender, $this->greetingReply()));
        }

        // 1. Cek sapaan / greeting — kalau sudah first-time, skip agar tidak double
        if (!$isFirstTime && $this->isGreeting($pesan)) {
            $reply = $this->greetingReply();
            dispatch(new SendWhatsappMessage($sender, $reply));
            return $this->quickResponse();
        }

        // 2. Cek ucapan terima kasih
        if ($this->isThankYou($pesan)) {
            $replies = [
                "Sama-sama! 😊 Senang bisa membantu. Silakan tanya lagi kapan saja ya!",
                "Terima kasih kembali! 🙏 Kalau ada kata lain yang ingin diterjemahkan, langsung kirim aja ya.",
                "Sama-sama kak! 😄 Jangan ragu tanya lagi kalau butuh terjemahan bahasa Lampung.",
            ];
            dispatch(new SendWhatsappMessage($sender, $replies[array_rand($replies)]));
            return $this->quickResponse();
        }

        // 3. Cek bantuan / help / menu
        if ($this->isHelp($pesan)) {
            $reply = $this->helpReply();
            dispatch(new SendWhatsappMessage($sender, $reply));
            return $this->quickResponse();
        }

        // 4. Proses terjemahan — ekstrak keyword dari berbagai pola kalimat
        $keyword = $this->extractKeyword($pesan);

        \Log::info('Keyword extraction', [
            'original' => $pesan,
            'keyword' => $keyword,
        ]);

        // Kalau keyword kosong setelah ekstraksi, minta input ulang
        if (empty($keyword)) {
            $reply = "Hmm, sepertinya saya belum mengerti maksudnya 🤔\n\n";
            $reply .= "Coba kirim kata yang ingin diterjemahkan, contoh:\n";
            $reply .= "• *rumah*\n";
            $reply .= "• *apa bahasa lampungnya terima kasih*\n";
            $reply .= "• *bahasa lampungnya selamat pagi*\n\n";
            $reply .= "Ketik *menu* untuk melihat panduan lengkap.";
            dispatch(new SendWhatsappMessage($sender, $reply));
            return $this->quickResponse();
        }

        // Cari di database kamus
        $data = Kamus::where('indonesia', $keyword)->first()
            ?? Kamus::whereRaw('LOWER(indonesia) = ?', [strtolower($keyword)])->first()
            ?? Kamus::where('indonesia', 'like', "%$keyword%")->first();

        \Log::info('Database lookup', [
            'keyword' => $keyword,
            'found_id' => $data?->id,
            'found_word' => $data?->indonesia,
        ]);

        if ($data) {
            // Format balasan terjemahan
            $replyText = "✅ *Terjemahan: " . ucfirst($keyword) . "*\n\n";
            $replyText .= "🔸 Dialek A: " . $data->dialek_a . "\n";
            $replyText .= "🔸 Dialek O: " . $data->dialek_o . "\n\n";
            $replyText .= "_Kirim kata lain untuk terjemahan berikutnya_ 😊";

            // Dispatch teks
            dispatch(new SendWhatsappMessage($sender, $replyText));

            // Dispatch audio dialek A (jika ada)
            if (!empty($data->audio_a)) {
                $audioPath = public_path('storage/audio/' . $data->audio_a);
                if (file_exists($audioPath)) {
                    dispatch(new SendWhatsappAudio($sender, $audioPath))->delay(2);
                }
            }

            // Dispatch audio dialek O (jika ada)
            if (!empty($data->audio_o)) {
                $audioPath = public_path('storage/audio/' . $data->audio_o);
                if (file_exists($audioPath)) {
                    dispatch(new SendWhatsappAudio($sender, $audioPath))->delay(4);
                }
            }

        } else {
            // Kata tidak ditemukan — beri saran
            $reply = "Maaf, kata *" . $keyword . "* belum tersedia di kamus kami 😔\n\n";
            $reply .= "Coba kata lain ya! Contoh:\n";
            $reply .= "• rumah\n• terima kasih\n• selamat pagi\n• permisi\n\n";
            $reply .= "_Kamus kami terus diperbarui!_ 📚";
            dispatch(new SendWhatsappMessage($sender, $reply));
        }

        return $this->quickResponse();
    }

    // ========================================
    // HELPER: Deteksi intent
    // ========================================

    /**
     * Cek apakah pesan adalah sapaan / greeting
     */
    private function isGreeting(string $pesan): bool
    {
        $greetings = [
            'halo', 'hai', 'hey', 'hi', 'hallo', 'helo',
            'assalamualaikum', 'assalamu\'alaikum', 'assalamualaikum wr wb',
            'waalaikumsalam', 'wa\'alaikumsalam',
            'p', 'hai bot', 'halo bot', 'hi bot',
            'bot', 'mulai', 'start',
        ];

        return in_array($pesan, $greetings);
    }

    /**
     * Cek apakah pesan adalah ucapan terima kasih
     */
    private function isThankYou(string $pesan): bool
    {
        $thanks = [
            'terima kasih', 'terimakasih', 'makasih', 'makasi',
            'thanks', 'thank you', 'thx', 'tq',
            'terima kasih banyak', 'makasih banyak', 'makasih ya',
            'ok makasih', 'oke makasih', 'oke terima kasih',
            'siap terima kasih', 'siap makasih',
        ];

        return in_array($pesan, $thanks);
    }

    /**
     * Cek apakah pesan adalah permintaan bantuan
     */
    private function isHelp(string $pesan): bool
    {
        $helpWords = [
            'bantuan', 'help', 'cara', 'menu', 'panduan',
            'cara pakai', 'cara penggunaan', 'gimana caranya',
            'bagaimana', 'fitur', 'bisa apa aja',
            'cara kerja', 'petunjuk',
        ];

        return in_array($pesan, $helpWords);
    }

    // ========================================
    // HELPER: Ekstrak keyword terjemahan
    // ========================================

    /**
     * Ekstrak kata kunci dari berbagai pola kalimat pengguna
     */
    private function extractKeyword(string $pesan): string
    {
        // Normalisasi typo umum sebelum regex matching
        $normalized = $pesan;
        $typoFixes = [
            '/bah?aha\b/i' => 'bahasa',       // bahaha, baha
            '/bahas\b/i' => 'bahasa',          // bahas (tanpa 'a' di akhir)
            '/lampong/i' => 'lampung',         // lampong
            '/lampoung/i' => 'lampung',        // lampoung
            '/lampuung/i' => 'lampung',        // lampuung
            '/lampng/i' => 'lampung',          // lampng
        ];
        foreach ($typoFixes as $pattern => $fix) {
            $normalized = preg_replace($pattern, $fix, $normalized);
        }

        // Pola regex untuk menangkap keyword dari kalimat natural
        // URUTAN PENTING — pola lebih spesifik harus duluan
        $patterns = [
            // "gimana bilang [kata] dalam bahasa lampung"
            '/(?:gimana|bagaimana)\s+(?:bilang|ngomong|cara\s+bilang)\s+(.+)\s+(?:dalam|di|ke|pake|pakai)\s+bahasa\s+lampung/i',

            // "gimana bilang [kata]" (tanpa suffix)
            '/(?:gimana|bagaimana)\s+(?:bilang|ngomong|cara\s+bilang)\s+(.+)/i',

            // "[kata] itu apa bahasa lampungnya"
            '/^(.+?)\s+(?:itu\s+)?apa\s+bahasa\s+lampung(?:nya|ne)?/i',

            // "[kata] bahasa lampungnya apa" / "[kata] dalam bahasa lampung apa"
            '/^(.+?)\s+(?:bahasa\s+lampung(?:nya|ne)?|dalam\s+bahasa\s+lampung)\s*(?:apa)?$/i',

            // "bahasa lampungnya [kata] apa" / "bahasa lampungnya [kata]"
            '/bahasa\s+lampung(?:nya|ne)?\s+(?:dari\s+)?(?:kata\s+)?(.+?)(?:\s+apa\s*\??)?$/i',

            // "apa bahasa lampungnya [kata]"
            '/(?:apa\s+)?bahasa\s+lampung(?:nya|ne)?\s+(?:dari\s+)?(?:kata\s+)?(.+)/i',

            // "apa arti [kata] dalam bahasa lampung"
            '/(?:apa\s+)?arti(?:nya)?\s+(?:kata\s+)?(.+?)(?:\s+(?:dalam|di|ke)\s+bahasa\s+lampung)?$/i',

            // "terjemahkan [kata]" / "translate [kata]" / "tolong artikan [kata]"
            '/(?:tolong\s+)?(?:terjemah(?:kan|in)?|translate|artikan)\s+(?:kata\s+)?(.+)/i',

            // "terjemahan [kata]" / "terjemahan dari [kata]"
            '/terjemahan\s+(?:dari\s+)?(?:kata\s+)?(.+)/i',

            // "[kata] artinya apa" / "[kata] apa artinya"
            '/^(.+?)\s+(?:arti(?:nya)?|terjemahan(?:nya)?)\s*(?:apa)?$/i',

            // "apa itu [kata]"
            '/apa\s+(?:itu|sih)\s+(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $keyword = trim($matches[1]);
                // Bersihkan tanda tanya di akhir
                $keyword = rtrim($keyword, '? ');
                if (!empty($keyword)) {
                    return $keyword;
                }
            }
        }

        // Fallback: bersihkan kata-kata umum yang bukan keyword
        $removeWords = [
            'apa bahasa lampungnya', 'bahasa lampungnya', 'bahasa lampung dari',
            'arti kata', 'terjemahan', 'apa itu', 'tolong artikan',
            'terjemahkan', 'artikan', '?',
        ];

        $keyword = trim(str_replace($removeWords, '', $normalized));
        $keyword = rtrim($keyword, '? ');

        return $keyword;
    }

    // ========================================
    // HELPER: Response templates
    // ========================================

    /**
     * Balasan sapaan
     */
    private function greetingReply(): string
    {
        $greetings = [
            "Halo! 👋 Selamat datang di *Bot Penerjemah Bahasa Lampung*! 🐘\n\nSaya bisa membantu menerjemahkan kata dari Bahasa Indonesia ke Bahasa Lampung (Dialek A & Dialek O).\n\n*Cara pakai:*\nLangsung kirim kata yang ingin diterjemahkan, contoh:\n• *rumah*\n• *apa bahasa lampungnya terima kasih*\n• *bahasa lampungnya selamat pagi apa*\n\nKetik *menu* untuk panduan lengkap 📖",

            "Hai! 😊 Saya adalah *Bot Penerjemah Bahasa Lampung*.\n\nMau terjemahkan kata apa hari ini? Langsung ketik aja kata yang ingin diterjemahkan!\n\nContoh: *rumah*, *terima kasih*, *selamat pagi*\n\nKetik *menu* untuk bantuan 📖",

            "Halo kak! 👋 Selamat datang!\n\nSaya siap membantu menerjemahkan kata ke *Bahasa Lampung* 🐘\n\nSilakan kirim kata yang ingin diterjemahkan.\nContoh: ketik *permisi* atau *apa bahasa lampungnya rumah*\n\nKetik *menu* untuk info lebih lanjut 📖",
        ];

        return $greetings[array_rand($greetings)];
    }

    /**
     * Balasan menu bantuan
     */
    private function helpReply(): string
    {
        return "📖 *Panduan Bot Penerjemah Bahasa Lampung*\n\n"
            . "*Cara menerjemahkan:*\n"
            . "Kirim kata/kalimat dengan cara berikut:\n\n"
            . "1️⃣ Ketik langsung kata:\n"
            . "   → *rumah*\n"
            . "   → *terima kasih*\n\n"
            . "2️⃣ Pakai kalimat:\n"
            . "   → *apa bahasa lampungnya rumah*\n"
            . "   → *bahasa lampungnya selamat pagi apa*\n"
            . "   → *terjemahkan permisi*\n"
            . "   → *arti kata rumah*\n\n"
            . "📢 *Fitur:*\n"
            . "• Terjemahan dalam 2 dialek (A & O)\n"
            . "• Audio pengucapan (jika tersedia) 🔊\n\n"
            . "Langsung coba sekarang! Ketik kata apa saja 😊";
    }

    /**
     * Response cepat untuk webhook
     */
    private function quickResponse()
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Message processed',
            'code' => 200
        ], 200);
    }
}
