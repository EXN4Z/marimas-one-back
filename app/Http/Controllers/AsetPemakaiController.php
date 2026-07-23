<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
use App\Models\AsetPemakai;
use App\Models\AsetPenanganan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AsetPemakaiController extends Controller
{
    use GeneratesStrukNumber;

    /**
     * GET /api/aset-pemakai/riwayat
     * Riwayat global SEMUA aktivitas aset — bukan cuma pinjam/kembali, tapi juga
     * lapor kerusakan + selesai perbaikan — digabung jadi satu feed, terbaru
     * duluan. Buat panel riwayat di halaman Inventaris tab Aset. Jangan dicampur
     * sama riwayat peminjaman barang (beda tabel/beda satuan).
     */
    public function riwayat(Request $request)
    {
        $limit = (int) $request->query('limit', 10);
        $ambil = $limit * 2; // ambil lebih banyak dari tiap sumber biar aman pas digabung+dipotong

        $events = collect();

<<<<<<< HEAD
        AsetPemakai::with(['aset:id,kode_aset,merek,tipe', 'pekerja.user:id,name'])
=======
        AsetPemakai::with(['aset:id,kode_aset,merek,tipe', 'pekerja.user:id,name', 'user:id,name'])
>>>>>>> 3c98b01764fee6937e600bb8b6187bd05f5af980
            ->where('status', 'disetujui')
            ->latest('tanggal_penerimaan')
            ->limit($ambil)
            ->get()
            ->each(function ($p) use (&$events) {
<<<<<<< HEAD
                $nama = $p->pekerja?->user?->name ?? '-';
=======
                // penerima bisa karyawan (lewat pekerja.user) atau akun cabang (lewat user langsung)
                $nama = $p->pekerja?->user?->name ?? $p->user?->name ?? '-';
>>>>>>> 3c98b01764fee6937e600bb8b6187bd05f5af980
                $events->push([
                    'type' => 'pinjam',
                    'waktu' => $p->tanggal_penerimaan,
                    'nama' => $nama,
                    'aset' => $p->aset,
                ]);
                if ($p->tanggal_pengembalian) {
                    $events->push([
                        'type' => 'kembali',
                        'waktu' => $p->tanggal_pengembalian,
                        'nama' => $nama,
                        'aset' => $p->aset,
                    ]);
                }
            });

        AsetPenanganan::with('aset:id,kode_aset,merek,tipe')
            ->latest('tanggal_lapor')
            ->limit($ambil)
            ->get()
            ->each(function ($pn) use (&$events) {
                $events->push([
                    'type' => 'lapor_rusak',
                    'waktu' => $pn->tanggal_lapor,
                    'nama' => null,
                    'aset' => $pn->aset,
                    'keluhan' => $pn->keluhan,
                ]);
                if ($pn->tanggal_selesai) {
                    $events->push([
                        'type' => 'selesai_perbaikan',
                        'waktu' => $pn->tanggal_selesai,
                        'nama' => null,
                        'aset' => $pn->aset,
                        'hasil' => $pn->hasil,
                    ]);
                }
            });

        $riwayat = $events->sortByDesc('waktu')->values()->take($limit);

        return response()->json($riwayat);
    }

    /**
     * POST /aset/{aset}/pemakai
     * Admin serah-terima aset langsung ke pekerja ATAU akun cabang (tanpa lewat
     * alur request/approve). Kirim salah satu: pekerja_id (karyawan) atau
     * user_id (akun cabang) — nggak boleh dua-duanya, nggak boleh kosong dua-duanya.
     * Aset harus 'tersedia'. Struk penerimaan digenerate otomatis.
     */
    public function store(Request $request, Aset $aset)
    {
        if ($aset->status !== 'tersedia') {
            return response()->json(['message' => 'Aset ini sedang tidak tersedia untuk diserahkan.'], 422);
        }

        $validated = $request->validate([
            'pekerja_id' => 'required_without:user_id|nullable|exists:pekerja,id',
            'user_id' => [
                'required_without:pekerja_id',
                'nullable',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($value && \App\Models\User::where('id', $value)->where('role', 'cabang')->doesntExist()) {
                        $fail('Akun yang dipilih bukan akun cabang.');
                    }
                },
            ],
            'nomor_penerimaan' => 'nullable|string',
            'tanggal_penerimaan' => 'required|date',
            'catatan_penerimaan' => 'nullable|string',
        ]);

        $pemakai = DB::transaction(function () use ($aset, $request, $validated) {
            $noStruk = $this->generateNoStruk('STJ', 'aset_pemakai', 'no_struk_penerimaan');

            $pemakai = AsetPemakai::create([
                'aset_id' => $aset->id,
                'pekerja_id' => $validated['pekerja_id'] ?? null,
                'user_id' => $validated['user_id'] ?? null,
                'status' => 'disetujui',
                'requested_by_user_id' => $request->user()?->id,
                'nomor_penerimaan' => $validated['nomor_penerimaan'] ?? null,
                'no_struk_penerimaan' => $noStruk,
                'tanggal_penerimaan' => $validated['tanggal_penerimaan'],
                'catatan_penerimaan' => $validated['catatan_penerimaan'] ?? null,
            ]);

            $aset->update(['status' => 'dipakai']);

            return $pemakai;
        });

        return response()->json($pemakai->load('pekerja.user', 'user', 'aset'), 201);
    }

    /**
     * POST /api/aset/{aset}/pinjam
     * Karyawan request pinjam aset. Butuh approval admin sebelum resmi jadi pemakai.
     * (Akun cabang tidak lewat alur ini — cabang cuma diserahkan langsung oleh admin lewat store()).
     */
    public function requestPinjam(Request $request, Aset $aset)
    {
        $user = $request->user();
        $pekerjaId = $user->pekerja?->id;

        if (!$pekerjaId) {
            return response()->json(['message' => 'Akun kamu belum terhubung ke data pekerja.'], 422);
        }

        if ($aset->status !== 'tersedia') {
            return response()->json(['message' => 'Aset ini sedang tidak tersedia untuk dipinjam.'], 422);
        }

        if ($aset->pemakai()->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'Sudah ada permintaan pinjam yang masih menunggu persetujuan untuk aset ini.'], 422);
        }

        $validated = $request->validate([
            'catatan_penerimaan' => 'nullable|string',
        ]);

        $pemakai = AsetPemakai::create([
            'aset_id' => $aset->id,
            'pekerja_id' => $pekerjaId,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
            'catatan_penerimaan' => $validated['catatan_penerimaan'] ?? null,
        ]);

        return response()->json($pemakai->load('pekerja.user'), 201);
    }

    /**
     * GET /api/aset-pemakai/pending
     * Admin: daftar request pinjam yang menunggu persetujuan.
     */
    public function pending()
    {
        $list = AsetPemakai::with(['aset', 'pekerja.user', 'user'])
            ->where('status', 'pending')
            ->latest()
            ->get();

        return response()->json($list);
    }

    /**
     * POST /api/aset-pemakai/{asetPemakai}/setujui
     * Admin approve request pinjam -> aset resmi jadi 'dipakai'.
     */
    public function setujui(Request $request, AsetPemakai $asetPemakai)
    {
        if ($asetPemakai->status !== 'pending') {
            return response()->json(['message' => 'Request ini sudah diproses sebelumnya.'], 422);
        }

        $validated = $request->validate([
            'nomor_penerimaan' => 'nullable|string',
            'tanggal_penerimaan' => 'nullable|date',
        ]);

        DB::transaction(function () use ($asetPemakai, $validated) {
            $noStruk = $this->generateNoStruk('STJ', 'aset_pemakai', 'no_struk_penerimaan');

            $asetPemakai->update([
                'status' => 'disetujui',
                'nomor_penerimaan' => $validated['nomor_penerimaan'] ?? $asetPemakai->nomor_penerimaan,
                'no_struk_penerimaan' => $noStruk,
                'tanggal_penerimaan' => $validated['tanggal_penerimaan'] ?? now()->toDateString(),
            ]);

            $asetPemakai->aset()->update(['status' => 'dipakai']);
        });

        return response()->json($asetPemakai->load('pekerja.user', 'user', 'aset'));
    }

    /**
     * POST /api/aset-pemakai/{asetPemakai}/kembalikan
     * Admin terima kembali aset dari pemakai. Wajib sertain no_struk_penerimaan
     * (struk asli pas serah-terima) sebagai bukti pengembalian ini benar.
     * Ditolak kalau masih ada laporan penanganan/perbaikan yang belum selesai.
     */
    public function kembalikan(Request $request, AsetPemakai $asetPemakai)
    {
        $validated = $request->validate([
            'no_struk_penerimaan' => 'required|string',
            'nomor_pengembalian' => 'nullable|string',
            'tanggal_pengembalian' => 'required|date',
            'catatan_pengembalian' => 'nullable|string',
        ]);

        if ($asetPemakai->status !== 'disetujui') {
            throw ValidationException::withMessages([
                'status' => ['Data pemakaian ini bukan pemakaian aktif yang bisa dikembalikan.'],
            ]);
        }

        if ($validated['no_struk_penerimaan'] !== $asetPemakai->no_struk_penerimaan) {
            throw ValidationException::withMessages([
                'no_struk_penerimaan' => ['Nomor struk penerimaan tidak cocok. Pengembalian wajib menyertakan bukti serah-terima yang benar.'],
            ]);
        }

        $adaPenangananBelumSelesai = AsetPenanganan::where('aset_pemakai_id', $asetPemakai->id)
            ->whereNull('tanggal_selesai')
            ->exists();

        if ($adaPenangananBelumSelesai) {
            throw ValidationException::withMessages([
                'penanganan' => ['Masih ada laporan penanganan/perbaikan yang belum diselesaikan untuk pemakaian ini.'],
            ]);
        }

        DB::transaction(function () use ($asetPemakai, $validated) {
            $noStruk = $this->generateNoStruk('KBL', 'aset_pemakai', 'no_struk_pengembalian');

            $asetPemakai->update([
                'nomor_pengembalian' => $validated['nomor_pengembalian'] ?? $asetPemakai->nomor_pengembalian,
                'no_struk_pengembalian' => $noStruk,
                'tanggal_pengembalian' => $validated['tanggal_pengembalian'],
                'catatan_pengembalian' => $validated['catatan_pengembalian'] ?? $asetPemakai->catatan_pengembalian,
            ]);

            $asetPemakai->aset()->update(['status' => 'tersedia']);
        });

        return response()->json($asetPemakai->fresh()->load('pekerja.user', 'user', 'aset'));
    }

    /**
     * POST /api/aset-pemakai/{asetPemakai}/tolak
     * Admin tolak request pinjam -> aset tetap 'tersedia'.
     */
    public function tolak(Request $request, AsetPemakai $asetPemakai)
    {
        if ($asetPemakai->status !== 'pending') {
            return response()->json(['message' => 'Request ini sudah diproses sebelumnya.'], 422);
        }

        $validated = $request->validate([
            'catatan_penolakan' => 'nullable|string',
        ]);

        $asetPemakai->update([
            'status' => 'ditolak',
            'catatan_penolakan' => $validated['catatan_penolakan'] ?? null,
        ]);

        return response()->json($asetPemakai->load('pekerja.user', 'user', 'aset'));
    }
}