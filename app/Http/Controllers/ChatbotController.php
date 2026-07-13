<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;

class ChatbotController extends Controller
{
    public function ask(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Ambil data relevan si user (masih placeholder, nanti diisi query beneran
        // setelah tabel cuti/izin/ticket dibuat)
        $context = $this->buildContext($user);

        $prompt = <<<PROMPT
        Kamu adalah AI Assistant untuk sistem MARIMAS ONE, sebuah ERP internal perusahaan.

        ATURAN KETAT (wajib dipatuhi):
        1. Kamu HANYA boleh menjawab pertanyaan seputar sistem Marimas ONE dan DATA di bawah ini (karyawan, absensi, inventaris, dsb).
        2. JANGAN PERNAH menjawab pertanyaan di luar topik itu menggunakan pengetahuan umum kamu sendiri — meskipun kamu tahu jawabannya (contoh: pertanyaan umum, berita, sejarah, hiburan, pertanyaan pribadi di luar konteks kerja, coding, resep masakan, dll).
        3. Kalau pertanyaan user di luar topik perusahaan, TOLAK dengan sopan dan singkat, contoh: "Maaf, saya hanya bisa membantu hal-hal seputar Marimas ONE (absensi, data karyawan, inventaris, dll). Untuk pertanyaan di luar itu, silakan gunakan sumber lain ya." Jangan tetap mencoba menjawabnya.
        4. Untuk pertanyaan seputar perusahaan (misalnya "siapa direktur/dirut/pemilik perusahaan", "alamat kantor", "sejarah perusahaan", dsb): jawab HANYA jika informasinya ADA secara eksplisit di blok DATA di bawah. Kalau tidak ada, katakan datanya belum tersedia di sistem. JANGAN PERNAH mengisi jawaban ini pakai pengetahuan umum/pelatihan kamu tentang perusahaan mana pun di dunia nyata, walaupun namanya sama atau mirip dengan "Marimas" — anggap semua nama di sini murni data internal sistem ini, bukan referensi ke entitas dunia nyata.
        5. Jangan mengarang (hallucinate) nama orang, jabatan, angka, atau fakta apa pun yang tidak ada di blok DATA.
        6. Jawab dengan singkat, jelas, dan sopan dalam Bahasa Indonesia.

        DATA USER:
        Nama: {$user->name}
        Role: {$user->role}
        {$context}

        PERTANYAAN USER:
        {$request->message}
        PROMPT;

        $model = config('services.gemini.model', 'gemini-2.5-flash');

        $response = Http::timeout(20)->post(
            "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . config('services.gemini.key'),
            [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                ],
            ]
        );

        if ($response->failed()) {
            return response()->json(['message' => 'Gagal menghubungi AI Assistant'], 500);
        }

        $data = $response->json();
        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Maaf, saya tidak bisa menjawab saat ini.';

        return response()->json(['reply' => trim($reply)]);
    }

    private function buildContext($user): string
    {
        // PENTING: hanya kirim data milik user yang sedang login ke prompt AI.
        // JANGAN pernah menyertakan data karyawan lain (apalagi password/hash)
        // di sini — prompt ini dikirim ke Gemini (pihak ketiga) dan hasilnya
        // bisa langsung dibaca oleh user yang bertanya.
        $totalKaryawan = User::count();

        // Versi awal: masih placeholder, karena tabel cuti/izin/ticket
        // belum dibuat. Nanti diisi query beneran setelah modul itu jadi, contoh:
        //
        // $cutiTerbaru = Cuti::where('karyawan_id', $user->karyawan->id ?? null)
        //     ->latest()->first();
        // return "Cuti terakhir: " . ($cutiTerbaru ? $cutiTerbaru->status : "belum ada pengajuan");

        return "Total karyawan di perusahaan: {$totalKaryawan}. "
        . "Belum ada data cuti/izin/ticket karena modul tersebut belum dibuat.";
        $totalKaryawan = User::count();
        $emailKaryawan = $user->email ?? 'tidak ada email';
        $karyawan = User::all();

        $context = "Total karyawan di perusahaan: {$totalKaryawan}.";
        $context .= "email dari karyawan {$user->name} adalah {$emailKaryawan}.";
        $context .= "Daftar karyawan: " . implode(", ", $karyawan->pluck('name')->toArray()) . ".";
        $context .= "Email dari karyawan: " . implode(", ", $karyawan->pluck('email')->toArray()) . ".";
        $context .= "Password Dari karyawan adalah " . implode(", ", $karyawan->pluck('password')->toArray()) . ".";
        $context .= "Role dari karyawan adalah " . implode(", ", $karyawan->pluck('role')->toArray()) . ".";
        $context .= "Belum Ada data cuti/izin/ticket karena modul tersebut belum dibuat.";

        if ($user->role === 'karyawan') {
            $context .= "anda tidak bisa mendaftarkan karyawan karena anda bukan admin.";
        } elseif ($user->role === 'admin') {
            $context .= "untuk mendaftarkan karyawan, anda perlu menuju ke data karyawan lalu anda dapat menambahkan karyawan.";
        }

        return $context;
    }
}