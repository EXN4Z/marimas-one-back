<?php

namespace App\Http\Controllers;

use App\Models\PengajuanIzin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class IzinController extends Controller
{
    // GET /api/izin — daftar pengajuan (role-aware) + filter
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PengajuanIzin::with(['karyawan:id,name,email', 'karyawan.pekerja.departemen', 'karyawan.pekerja.jabatan', 'reviewer:id,name']);

        $query = $this->scopeByRole($query, $user);

        if ($request->filled('status') && $request->status !== 'semua') {
            $query->where('status', $request->status);
        }

        if ($request->filled('jenis_izin')) {
            $query->where('jenis_izin', $request->jenis_izin);
        }

        if ($request->filled('bulan')) {
            $query->whereMonth('tanggal_mulai', $request->bulan);
        }

        if ($request->filled('tahun')) {
            $query->whereYear('tanggal_mulai', $request->tahun);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nomor_izin', 'like', "%{$search}%")
                    ->orWhere('alasan', 'like', "%{$search}%")
                    ->orWhereHas('karyawan', function ($qu) use ($search) {
                        $qu->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json(
            $query->latest()->paginate($request->get('per_page', 15))
        );
    }

    // GET /api/izin/dashboard — ringkasan angka untuk halaman dashboard
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $base = $this->scopeByRole(PengajuanIzin::query(), $user);

        $today = now()->toDateString();

        return response()->json([
            'total_pengajuan' => (clone $base)->count(),
            'menunggu_persetujuan' => (clone $base)->where('status', 'pending')->count(),
            'disetujui' => (clone $base)->where('status', 'disetujui')->count(),
            'ditolak' => (clone $base)->where('status', 'ditolak')->count(),
            'izin_hari_ini' => (clone $base)
                ->whereDate('tanggal_mulai', '<=', $today)
                ->whereDate('tanggal_selesai', '>=', $today)
                ->whereIn('status', ['disetujui', 'pending'])
                ->count(),
            'riwayat_terbaru' => (clone $base)
                ->with('karyawan:id,name')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    // GET /api/izin/statistik — data untuk grafik
    public function statistik(Request $request)
    {
        $user = $request->user();
        $tahun = $request->get('tahun', now()->year);
        $base = $this->scopeByRole(PengajuanIzin::query(), $user)->whereYear('tanggal_mulai', $tahun);

        // Pengajuan per bulan (1-12)
        $perBulan = (clone $base)
            ->selectRaw('EXTRACT(MONTH FROM tanggal_mulai) as bulan, count(*) as total')
            ->groupBy('bulan')
            ->orderBy('bulan')
            ->get()
            ->keyBy('bulan');

        $pengajuanPerBulan = [];
        for ($i = 1; $i <= 12; $i++) {
            $pengajuanPerBulan[] = [
                'bulan' => $i,
                'total' => (int) ($perBulan[$i]->total ?? 0),
            ];
        }

        // Jenis izin terbanyak
        $jenisTerbanyak = (clone $base)
            ->selectRaw('jenis_izin, count(*) as total')
            ->groupBy('jenis_izin')
            ->orderByDesc('total')
            ->get();

        // Persentase approve/reject dari pengajuan yang sudah diputuskan
        $totalDiputuskan = (clone $base)->whereIn('status', ['disetujui', 'ditolak'])->count();
        $totalApprove = (clone $base)->where('status', 'disetujui')->count();
        $totalReject = (clone $base)->where('status', 'ditolak')->count();

        return response()->json([
            'pengajuan_per_bulan' => $pengajuanPerBulan,
            'jenis_izin_terbanyak' => $jenisTerbanyak,
            'persentase_approve' => $totalDiputuskan ? round($totalApprove / $totalDiputuskan * 100, 1) : 0,
            'persentase_reject' => $totalDiputuskan ? round($totalReject / $totalDiputuskan * 100, 1) : 0,
        ]);
    }

    // GET /api/izin/{id} — detail pengajuan
    public function show(Request $request, $id)
    {
        $user = $request->user();

        $izin = $this->scopeByRole(PengajuanIzin::query(), $user)
            ->with(['karyawan', 'karyawan.pekerja.departemen', 'karyawan.pekerja.jabatan', 'reviewer:id,name'])
            ->findOrFail($id);

        return response()->json($izin);
    }

    // POST /api/izin — buat pengajuan baru
    public function store(Request $request)
    {
        $request->validate([
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'jenis_izin' => 'required|in:' . implode(',', PengajuanIzin::JENIS),
            'alasan' => 'required|string|max:1000',
            'kontak_darurat' => 'nullable|string|max:50',
            'bukti' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
        ]);

        $buktiPath = null;
        if ($request->hasFile('bukti')) {
            $buktiPath = $request->file('bukti')->store('bukti-izin', 'public');
        }

        $izin = PengajuanIzin::create([
            'nomor_izin' => PengajuanIzin::generateNomorIzin(),
            'karyawan_id' => $request->user()->id,
            'jenis_izin' => $request->jenis_izin,
            'tanggal_mulai' => $request->tanggal_mulai,
            'tanggal_selesai' => $request->tanggal_selesai,
            'alasan' => $request->alasan,
            'bukti_path' => $buktiPath,
            'kontak_darurat' => $request->kontak_darurat,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Pengajuan izin berhasil dibuat.',
            'data' => $izin,
        ], 201);
    }

    // PUT /api/izin/{id} — edit pengajuan (hanya pemilik, hanya selama pending)
    public function update(Request $request, $id)
    {
        $izin = PengajuanIzin::findOrFail($id);
        $user = $request->user();

        if ($izin->karyawan_id !== $user->id) {
            return response()->json(['message' => 'Anda tidak punya akses ke pengajuan ini.'], 403);
        }

        if ($izin->status !== 'pending') {
            return response()->json(['message' => 'Hanya pengajuan berstatus pending yang bisa diedit.'], 400);
        }

        $request->validate([
            'tanggal_mulai' => 'sometimes|date',
            'tanggal_selesai' => 'sometimes|date|after_or_equal:tanggal_mulai',
            'jenis_izin' => 'sometimes|in:' . implode(',', PengajuanIzin::JENIS),
            'alasan' => 'sometimes|string|max:1000',
            'kontak_darurat' => 'nullable|string|max:50',
            'bukti' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($request->hasFile('bukti')) {
            if ($izin->bukti_path) {
                Storage::disk('public')->delete($izin->bukti_path);
            }
            $izin->bukti_path = $request->file('bukti')->store('bukti-izin', 'public');
        }

        $izin->fill($request->only(['tanggal_mulai', 'tanggal_selesai', 'jenis_izin', 'alasan', 'kontak_darurat']));
        $izin->save();

        return response()->json([
            'message' => 'Pengajuan izin berhasil diperbarui.',
            'data' => $izin,
        ]);
    }

    // PATCH /api/izin/{id}/status — approve/reject/revisi (manajer, hr, admin)
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:disetujui,ditolak,revisi',
            'catatan_atasan' => 'nullable|string|max:1000',
        ]);

        $izin = PengajuanIzin::findOrFail($id);

        if ($izin->status !== 'pending') {
            return response()->json(['message' => 'Pengajuan ini sudah diproses sebelumnya.'], 400);
        }

        $izin->status = $request->status;
        $izin->catatan_atasan = $request->catatan_atasan;
        $izin->direview_oleh = $request->user()->id;
        $izin->direview_at = now();
        $izin->save();

        // TODO: kirim notifikasi ke karyawan pemohon (poin 7 di spec) —
        // butuh channel notifikasi (email/in-app) yang belum ada di project ini.

        return response()->json([
            'message' => 'Status pengajuan berhasil diperbarui.',
            'data' => $izin,
        ]);
    }

    // DELETE /api/izin/{id} — batalkan (hanya pemilik, hanya selama pending)
    public function destroy(Request $request, $id)
    {
        $izin = PengajuanIzin::findOrFail($id);
        $user = $request->user();

        if ($izin->karyawan_id !== $user->id) {
            return response()->json(['message' => 'Anda tidak punya akses ke pengajuan ini.'], 403);
        }

        if ($izin->status !== 'pending') {
            return response()->json(['message' => 'Hanya pengajuan berstatus pending yang bisa dibatalkan.'], 400);
        }

        if ($izin->bukti_path) {
            Storage::disk('public')->delete($izin->bukti_path);
        }

        $izin->delete();

        return response()->json(['message' => 'Pengajuan izin berhasil dibatalkan.']);
    }

    // Batasi query sesuai role: karyawan cuma lihat punya sendiri, manajer
    // lihat pengajuan dari departemen yang sama (asumsi: 1 manajer = 1
    // departemen, lewat relasi pekerja->departemen_id), hr & admin lihat semua.
    private function scopeByRole($query, $user)
    {
        if ($user->role === 'karyawan') {
            return $query->where('karyawan_id', $user->id);
        }

        if ($user->role === 'manajer') {
            $departemenId = $user->pekerja?->departemen_id;

            return $query->whereHas('karyawan.pekerja', function ($q) use ($departemenId) {
                $q->where('departemen_id', $departemenId);
            });
        }

        // hr & admin: lihat semua
        return $query;
    }
}