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
use Illuminate\Support\Facades\Cache;

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

        // BARU: deteksi permintaan data karyawan terlambat SEBELUM kirim ke Gemini,
        // hanya untuk staff (karyawan biasa tidak boleh lihat data orang lain)
        $isStaff = in_array($user->role, ['admin', 'hr', 'manajer']);

        if ($isStaff && $this->isMintaKaryawanTerlambat($request->message)) {
            return response()->json([
                'reply' => 'Berikut data karyawan yang terlambat bulan ini. Silakan pilih format:',
                'exportPrompt' => [
                    'jenis' => 'karyawan_terlambat',
                    'bulan' => now()->month,
                    'tahun' => now()->year,
                ],
            ]);
        }

        // ... lanjut ke buildContext() + call Gemini seperti biasa
    }

    private function isMintaKaryawanTerlambat(string $message): bool
    {
        $msg = strtolower($message);
        $adaSubjek = str_contains($msg, 'karyawan') || str_contains($msg, 'pegawai');
        $adaTerlambat = str_contains($msg, 'terlambat') || str_contains($msg, 'telat');

        return $adaSubjek && $adaTerlambat;
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