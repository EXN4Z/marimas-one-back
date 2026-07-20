<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Pekerja;
use App\Models\PengajuanIzin;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollController extends Controller
{
    // GET /api/payroll?bulan=7&tahun=2026 — rekap gaji bulanan per karyawan.
    //
    // Rumus:
    //   gaji_kotor  = gaji_pokok (dari jabatan) + tunjangan (dari jabatan)
    //   potongan    = potongan_telat + potongan_izin_tanpa_gaji + potongan_alpa
    //   gaji_bersih = gaji_kotor - potongan (minimal 0)
    //
    // - potongan_telat: jumlah hari telat x potongan_per_telat (flat, dari config).
    // - potongan_izin_tanpa_gaji: hari izin yang disetujui tapi jenisnya termasuk
    //   "tanpa gaji" (lihat config('payroll.jenis_izin_tanpa_gaji')), dipotong
    //   proporsional (gaji_pokok / hari_kerja_sebulan) per hari.
    // - potongan_alpa: hari kerja yang tidak ada absensi & tidak ada izin yang
    //   disetujui sama sekali (dianggap alpa / mangkir), dipotong dengan rumus
    //   proporsional yang sama.
    public function index(Request $request)
    {
        $bulan = (int) $request->get('bulan', now()->month);
        $tahun = (int) $request->get('tahun', now()->year);

        return response()->json(
            $this->hitungPayroll($bulan, $tahun)
        );
    }

    // GET /api/payroll/export?bulan=7&tahun=2026 — export CSV rekap gaji.
    public function export(Request $request): StreamedResponse
    {
        $bulan = (int) $request->get('bulan', now()->month);
        $tahun = (int) $request->get('tahun', now()->year);

        $data = $this->hitungPayroll($bulan, $tahun);
        $filename = "payroll-{$tahun}-{$bulan}.csv";

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'NIP', 'Nama', 'Jabatan', 'Departemen',
                'Gaji Pokok', 'Tunjangan', 'Gaji Kotor',
                'Hari Kerja', 'Hari Hadir', 'Hari Telat', 'Hari Izin (Dibayar)',
                'Hari Izin Tanpa Gaji', 'Hari Alpa',
                'Potongan Telat', 'Potongan Izin Tanpa Gaji', 'Potongan Alpa',
                'Total Potongan', 'Gaji Bersih',
            ]);
            foreach ($data as $row) {
                fputcsv($out, [
                    $row['nip'], $row['nama'], $row['jabatan'], $row['departemen'],
                    $row['gaji_pokok'], $row['tunjangan'], $row['gaji_kotor'],
                    $row['hari_kerja'], $row['hari_hadir'], $row['hari_telat'], $row['hari_izin'],
                    $row['hari_izin_tanpa_gaji'], $row['hari_alpa'],
                    $row['potongan_telat'], $row['potongan_izin_tanpa_gaji'], $row['potongan_alpa'],
                    $row['total_potongan'], $row['gaji_bersih'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function hitungPayroll(int $bulan, int $tahun): array
    {
        $potonganPerTelat = (float) config('payroll.potongan_per_telat', 50000);
        $jenisTanpaGaji = config('payroll.jenis_izin_tanpa_gaji', ['pribadi', 'lainnya']);

        $pekerjaList = Pekerja::with('user', 'jabatan', 'departemen')->get();

        $awalBulan = Carbon::create($tahun, $bulan, 1)->startOfDay();
        $akhirBulan = $awalBulan->copy()->endOfMonth()->endOfDay();
        $hariKerja = $this->hitungHariKerjaDalamPeriode($awalBulan, $akhirBulan);

        // Hitung kehadiran & keterlambatan per pekerja dalam satu query, biar
        // gak N+1 query ke tabel absensi tiap pekerja.
        $rekapAbsensi = Absensi::whereMonth('tanggal', $bulan)
            ->whereYear('tanggal', $tahun)
            ->whereIn('status', ['tepat_waktu', 'telat'])
            ->get()
            ->groupBy('karyawan_id');

        // Izin yang disetujui & overlap dengan bulan berjalan. PengajuanIzin
        // pakai karyawan_id = users.id (bukan pekerja.id), jadi dikelompokkan
        // per user, bukan per pekerja.
        $izinDisetujui = PengajuanIzin::where('status', 'disetujui')
            ->whereDate('tanggal_mulai', '<=', $akhirBulan)
            ->whereDate('tanggal_selesai', '>=', $awalBulan)
            ->get()
            ->groupBy('karyawan_id');

        return $pekerjaList->map(function (Pekerja $pekerja) use (
            $rekapAbsensi, $izinDisetujui, $potonganPerTelat, $jenisTanpaGaji,
            $hariKerja, $awalBulan, $akhirBulan
        ) {
            $absensiPekerja = $rekapAbsensi->get($pekerja->id, collect());
            $hariHadir = $absensiPekerja->count();
            $hariTelat = $absensiPekerja->where('status', 'telat')->count();

            $izinPekerja = $pekerja->user
                ? $izinDisetujui->get($pekerja->user->id, collect())
                : collect();

            [$hariIzinDibayar, $hariIzinTanpaGaji] = $this->hitungHariIzin(
                $izinPekerja, $jenisTanpaGaji, $awalBulan, $akhirBulan
            );

            // Sisa hari kerja yang gak kecover absensi maupun izin disetujui = alpa.
            $hariAlpa = max($hariKerja - $hariHadir - $hariIzinDibayar - $hariIzinTanpaGaji, 0);

            $gajiPokok = (float) ($pekerja->jabatan->gaji_pokok ?? 0);
            $tunjangan = (float) ($pekerja->jabatan->tunjangan ?? 0);
            $gajiKotor = $gajiPokok + $tunjangan;
            $gajiPerHari = $hariKerja > 0 ? ($gajiPokok / $hariKerja) : 0;

            $potonganTelat = $hariTelat * $potonganPerTelat;
            $potonganIzinTanpaGaji = (int) round($hariIzinTanpaGaji * $gajiPerHari);
            $potonganAlpa = (int) round($hariAlpa * $gajiPerHari);
            $totalPotongan = $potonganTelat + $potonganIzinTanpaGaji + $potonganAlpa;

            $gajiBersih = max($gajiKotor - $totalPotongan, 0);

            return [
                'pekerja_id' => $pekerja->id,
                'nip' => $pekerja->nip,
                'nama' => $pekerja->user->name ?? '-',
                'jabatan' => $pekerja->jabatan->nama ?? '-',
                'departemen' => $pekerja->departemen->nama ?? '-',

                'hari_kerja' => $hariKerja,
                'hari_hadir' => $hariHadir,
                'hari_telat' => $hariTelat,
                'hari_izin' => $hariIzinDibayar,
                'hari_izin_tanpa_gaji' => $hariIzinTanpaGaji,
                'hari_alpa' => $hariAlpa,

                'gaji_pokok' => $gajiPokok,
                'tunjangan' => $tunjangan,
                'gaji_kotor' => $gajiKotor,

                'potongan_telat' => $potonganTelat,
                'potongan_izin_tanpa_gaji' => $potonganIzinTanpaGaji,
                'potongan_alpa' => $potonganAlpa,
                'total_potongan' => $totalPotongan,

                'gaji_bersih' => $gajiBersih,
            ];
        })->values()->toArray();
    }

    // Jumlah hari kerja (Senin-Jumat) dalam sebuah periode tanggal, inklusif.
    private function hitungHariKerjaDalamPeriode(Carbon $awal, Carbon $akhir): int
    {
        $jumlah = 0;
        $cursor = $awal->copy();
        while ($cursor->lte($akhir)) {
            if (!$cursor->isWeekend()) {
                $jumlah++;
            }
            $cursor->addDay();
        }

        return $jumlah;
    }

    // Hitung jumlah hari kerja dari daftar izin yang disetujui, dipotong biar
    // cuma yang overlap dengan bulan berjalan, dipisah dibayar vs tanpa gaji.
    private function hitungHariIzin($izinList, array $jenisTanpaGaji, Carbon $awalBulan, Carbon $akhirBulan): array
    {
        $dibayar = 0;
        $tanpaGaji = 0;

        foreach ($izinList as $izin) {
            $mulai = Carbon::parse($izin->tanggal_mulai)->max($awalBulan);
            $selesai = Carbon::parse($izin->tanggal_selesai)->min($akhirBulan);

            if ($mulai->gt($selesai)) {
                continue;
            }

            $hari = 0;
            $cursor = $mulai->copy();
            while ($cursor->lte($selesai)) {
                if (!$cursor->isWeekend()) {
                    $hari++;
                }
                $cursor->addDay();
            }

            if (in_array($izin->jenis_izin, $jenisTanpaGaji, true)) {
                $tanpaGaji += $hari;
            } else {
                $dibayar += $hari;
            }
        }

        return [$dibayar, $tanpaGaji];
    }
}
