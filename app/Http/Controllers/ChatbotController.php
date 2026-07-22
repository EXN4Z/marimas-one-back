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
        'previous_export' => 'nullable|array',
    ]);

    $user = $request->user();

    if (!$user) {
        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    $isStaff = in_array($user->role, ['admin', 'hr', 'manajer']);

    if ($isStaff && ($intent = $this->detectAbsensiIntent($request->message, $request->input('previous_export')))) {
        $statusLabel = $intent['status'] === 'telat' ? 'terlambat' : 'tepat waktu';

        return response()->json([
            'reply' => "Berikut data karyawan yang {$statusLabel} untuk {$intent['label_periode']}. Silakan pilih format:",
            'exportPrompt' => [
                'jenis' => 'absensi_status',
                'status' => $intent['status'],
                'tanggal_mulai' => $intent['tanggal_mulai'],
                'tanggal_selesai' => $intent['tanggal_selesai'],
                'label' => $intent['label_periode'],
            ],
        ]);
    }

        // Ambil data relevan si user (masih placeholder, nanti diisi query beneran
        // setelah tabel izin/ticket dibuat)
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
    private function detectAbsensiIntent(string $message, ?array $previousExport = null): ?array
    {
        $msg = strtolower($message);

        $adaSubjekEksplisit = str_contains($msg, 'karyawan') || str_contains($msg, 'pegawai');

        $status = null;
        if (str_contains($msg, 'tepat waktu') || str_contains($msg, 'on time') || str_contains($msg, 'ontime')) {
            $status = 'tepat_waktu';
        } elseif (str_contains($msg, 'terlambat') || str_contains($msg, 'telat')) {
            $status = 'telat';
        }

        // Kalau tidak ada kata status sama sekali, jangan trigger — biar tetap ke Gemini
        if (!$status) {
            return null;
        }

        // Subjek dianggap "ada" kalau disebut eksplisit, ATAU pesan sebelumnya
        // memang lagi dalam konteks laporan absensi (lanjutan pertanyaan, contoh:
        // "gimana kalau yang tepat waktu" tanpa nyebut "karyawan" lagi)
        $adaKonteksSebelumnya = $previousExport && ($previousExport['jenis'] ?? null) === 'absensi_status';

        if (!$adaSubjekEksplisit && !$adaKonteksSebelumnya) {
            return null;
        }

        [$tanggalMulai, $tanggalSelesai, $label] = $this->parseRentangTanggal($msg, $previousExport);

        return [
            'status' => $status,
            'tanggal_mulai' => $tanggalMulai,
            'tanggal_selesai' => $tanggalSelesai,
            'label_periode' => $label,
        ];
    }

    private function parseRentangTanggal(string $msg, ?array $previousExport = null): array
    {
        $today = now();

        if (str_contains($msg, 'hari ini')) {
            return [$today->toDateString(), $today->toDateString(), 'hari ini'];
        }

        if (str_contains($msg, 'kemarin')) {
            $kemarin = $today->copy()->subDay();
            return [$kemarin->toDateString(), $kemarin->toDateString(), 'kemarin'];
        }

        if (preg_match('/(\d+)\s*hari/', $msg, $match)) {
            $n = (int) $match[1];
            $mulai = $today->copy()->subDays($n - 1);
            return [$mulai->toDateString(), $today->toDateString(), "{$n} hari terakhir"];
        }

        // BARU: tidak ada sinyal tanggal baru di pesan ini — kalau ada konteks
        // dari pesan sebelumnya, pakai rentang tanggal yang sama (lanjutan
        // pertanyaan), bukan reset ke bulan berjalan.
        if ($previousExport && isset($previousExport['tanggal_mulai'], $previousExport['tanggal_selesai'], $previousExport['label'])) {
            return [$previousExport['tanggal_mulai'], $previousExport['tanggal_selesai'], $previousExport['label']];
        }

        $mulai = $today->copy()->startOfMonth();
        $selesai = $today->copy()->endOfMonth();
        return [$mulai->toDateString(), $selesai->toDateString(), $today->translatedFormat('F Y')];
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