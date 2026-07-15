<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\Departemen;
use App\Models\Jabatan;
use App\Models\Absensi;
use App\Models\Ticket;
use App\Models\Barang;

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
        // PENTING: hanya kirim data AGREGAT (jumlah/rekap) ke prompt AI, bukan
        // data mentah tiap karyawan (nama/email/password semua orang). Prompt
        // ini dikirim ke Gemini (pihak ketiga) dan hasilnya bisa langsung
        // dibaca oleh user yang bertanya, jadi data sensitif tidak boleh ikut.

        $totalKaryawan = User::where('role', 'karyawan')->count();
        $totalAdmin = User::where('role', 'admin')->count();

        $perDepartemen = Departemen::withCount('pekerja')->get()
            ->map(fn ($d) => "{$d->nama}: {$d->pekerja_count} orang")
            ->implode(', ') ?: 'belum ada data departemen';

        $perJabatan = Jabatan::withCount('pekerja')->get()
            ->map(fn ($j) => "{$j->nama}: {$j->pekerja_count} orang")
            ->implode(', ') ?: 'belum ada data jabatan';

        $today = now()->format('Y-m-d');
        $absensiHariIni = Absensi::where('tanggal', $today)->count();

        $ticketAktif = Ticket::whereIn('status', Ticket::STATUS_AKTIF)->count();

        $stokRendah = Barang::whereColumn('stok', '<=', 'stok_minimum')->count();

        $context = "Total karyawan (role karyawan): {$totalKaryawan}. Total admin: {$totalAdmin}.\n";
        $context .= "Rekap jumlah karyawan per departemen: {$perDepartemen}.\n";
        $context .= "Rekap jumlah karyawan per jabatan: {$perJabatan}.\n";
        $context .= "Jumlah data absensi tercatat hari ini ({$today}): {$absensiHariIni}.\n";
        $context .= "Jumlah tiket yang masih aktif (pending/diproses): {$ticketAktif}.\n";
        $context .= "Jumlah jenis barang dengan stok di bawah/sama dengan stok minimum: {$stokRendah}.\n";

        if ($user->role === 'karyawan') {
            $context .= "Catatan: user yang bertanya adalah karyawan biasa (bukan admin), jadi jangan berikan data pribadi karyawan lain (nama/email/gaji orang lain), cukup jawab dalam bentuk jumlah/rekap seperti di atas. Anda tidak bisa mendaftarkan karyawan baru karena bukan admin.\n";
        } elseif ($user->role === 'admin') {
            $context .= "Catatan: user yang bertanya adalah admin. Untuk mendaftarkan karyawan baru, arahkan ke menu Data Karyawan lalu tombol tambah karyawan.\n";
        }

        return $context;
    }
}