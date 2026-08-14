<?php

namespace App\Imports;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
use App\Models\AsetPemakai;
use App\Models\AsetPenanganan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Import laporan penanganan aset yang SUDAH SELESAI (bulk import data
 * historis) -- HANYA untuk 2 hasil akhir: "diperbaiki" (tab Berhasil
 * Diperbaiki) & "rusak_berat" (tab Rusak Berat). Dipakai dari tombol
 * Import di 2 tab itu saja di Forum Penanganan Aset -- BUKAN buat bikin
 * laporan baru yang masih perlu diterima/diproses admin, karena Hasil &
 * Tanggal Selesai WAJIB diisi, jadi tiap baris langsung tercatat
 * "selesai" begitu diimport (setara hasil dari form "Selesaikan
 * Perbaikan" di UI, cuma lewat Excel & banyak baris sekaligus).
 *
 * Kolom yang diharapkan (nama header bebas huruf besar/kecil & spasi,
 * dinormalisasi otomatis) -- SATU BARIS = SATU LAPORAN:
 *   Kode Aset | Jenis Kerusakan | Keluhan | Pelapor | Tanggal Lapor
 *   | Tanggal Diterima | Tanggal Selesai | Hasil | Biaya Komponen
 *   | Biaya Jasa | Catatan
 *
 * - Kode Aset: WAJIB, harus sudah ada di tabel aset (dicocokkan exact,
 *   case-insensitive).
 * - Jenis Kerusakan: WAJIB, "software" atau "hardware".
 * - Keluhan: WAJIB.
 * - Pelapor: OPSIONAL, teks bebas (nama) -- gak ada kolom khusus di
 *   tabel aset_penanganan buat ini (kolom aset_pemakai_id butuh relasi
 *   peminjaman yang riskan salah tempel kalau dicocokkan otomatis dari
 *   Excel), jadi cuma ditempel sebagai baris tambahan di `catatan`.
 * - Tanggal Lapor / Tanggal Diterima: OPSIONAL, fallback ke Tanggal
 *   Selesai kalau kosong (buat rekonstruksi data lama yang gak lengkap).
 * - Tanggal Selesai: WAJIB -- inilah yang bikin laporan otomatis masuk
 *   kategori "selesai" begitu diimport.
 * - Hasil: WAJIB, terima "diperbaiki"/"berhasil diperbaiki" ATAU
 *   "rusak berat"/"rusak_berat" (dinormalisasi, gak case sensitive).
 * - Biaya Komponen / Biaya Jasa: WAJIB kalau Hasil = diperbaiki (rusak
 *   berat gak ada biaya perbaikan, sama seperti aturan form UI).
 * - Catatan: OPSIONAL.
 *
 * Efek ke tabel `aset`: kalau Hasil = rusak_berat, status aset dipaksa
 * jadi 'rusak_berat' & pemakaian aktif (kalau ada) ditutup otomatis --
 * SAMA seperti alur normal lewat form UI (lihat
 * AsetPenangananController::update()). Kalau Hasil = diperbaiki, status
 * aset SENGAJA TIDAK diutak-atik -- ini data historis, status aset saat
 * ini dianggap sudah benar apa adanya (beda dari alur form UI yang
 * memang lagi live mindahin status).
 */
class AsetPenangananImport implements ToCollection
{
    use GeneratesStrukNumber;

    protected $rowCount = 0;
    protected $errors = [];

    /** Alias yang diterima buat kolom Hasil -> key resmi di tabel */
    private const HASIL_ALIAS = [
        'diperbaiki' => 'diperbaiki',
        'berhasil_diperbaiki' => 'diperbaiki',
        'rusak_berat' => 'rusak_berat',
    ];

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            $this->errors[] = 'File kosong.';
            return;
        }

        $headers = $rows[0]->map(fn ($h) => $this->normalisasiHeader((string) $h))->toArray();
        $dataRows = $rows->slice(1);

        foreach ($dataRows as $index => $rawRow) {
            // +2: index 0 di $dataRows = baris ke-2 di Excel (baris 1 = header)
            $nomorBaris = $index + 2;

            $rowArray = $rawRow->toArray();
            if (count(array_filter($rowArray, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // baris kosong total, lewati diam-diam (bukan error)
            }

            $row = array_combine($headers, array_pad($rowArray, count($headers), null));

            try {
                $berhasil = DB::transaction(fn () => $this->prosesBaris($row, $nomorBaris));
                if ($berhasil) {
                    $this->rowCount++;
                }
            } catch (\Throwable $e) {
                $this->errors[] = "Baris {$nomorBaris}: " . $e->getMessage();
            }
        }
    }

    private function prosesBaris(array $row, int $nomorBaris): bool
    {
        $kodeAset = trim((string) ($row['kode_aset'] ?? ''));
        if ($kodeAset === '') {
            $this->errors[] = "Baris {$nomorBaris}: Kode Aset kosong, dilewati.";
            return false;
        }

        $aset = Aset::whereRaw('LOWER(kode_aset) = ?', [strtolower($kodeAset)])->first();
        if (!$aset) {
            $this->errors[] = "Baris {$nomorBaris}: Kode Aset \"{$kodeAset}\" tidak ditemukan, dilewati.";
            return false;
        }

        $jenisKerusakan = strtolower(trim((string) ($row['jenis_kerusakan'] ?? '')));
        if (!in_array($jenisKerusakan, ['software', 'hardware'], true)) {
            $this->errors[] = "Baris {$nomorBaris}: Jenis Kerusakan harus \"software\" atau \"hardware\", dilewati.";
            return false;
        }

        $keluhan = trim((string) ($row['keluhan'] ?? ''));
        if ($keluhan === '') {
            $this->errors[] = "Baris {$nomorBaris}: Keluhan kosong, dilewati.";
            return false;
        }

        $hasilKey = str_replace(' ', '_', strtolower(trim((string) ($row['hasil'] ?? ''))));
        $hasil = self::HASIL_ALIAS[$hasilKey] ?? null;
        if (!$hasil) {
            $this->errors[] = "Baris {$nomorBaris}: Hasil harus \"diperbaiki\" atau \"rusak berat\", dilewati.";
            return false;
        }

        $tanggalSelesai = $this->parseTanggal($row['tanggal_selesai'] ?? null);
        if (!$tanggalSelesai) {
            $this->errors[] = "Baris {$nomorBaris}: Tanggal Selesai wajib diisi & valid, dilewati.";
            return false;
        }

        $tanggalLapor = $this->parseTanggal($row['tanggal_lapor'] ?? null) ?? $tanggalSelesai;
        $tanggalDiterima = $this->parseTanggal($row['tanggal_diterima'] ?? null) ?? $tanggalSelesai;

        $biayaKomponen = null;
        $hargaJasa = null;

        if ($hasil === 'diperbaiki') {
            $biayaKomponenMentah = $row['biaya_komponen'] ?? null;
            $hargaJasaMentah = $row['biaya_jasa'] ?? $row['harga_jasa'] ?? null;

            if ($biayaKomponenMentah === null || $biayaKomponenMentah === '' || !is_numeric($biayaKomponenMentah)) {
                $this->errors[] = "Baris {$nomorBaris}: Biaya Komponen wajib diisi angka kalau Hasil = diperbaiki, dilewati.";
                return false;
            }
            if ($hargaJasaMentah === null || $hargaJasaMentah === '' || !is_numeric($hargaJasaMentah)) {
                $this->errors[] = "Baris {$nomorBaris}: Biaya Jasa wajib diisi angka kalau Hasil = diperbaiki, dilewati.";
                return false;
            }

            $biayaKomponen = (float) $biayaKomponenMentah;
            $hargaJasa = (float) $hargaJasaMentah;
        }

        $pelapor = trim((string) ($row['pelapor'] ?? ''));
        $catatan = trim((string) ($row['catatan'] ?? ''));
        if ($pelapor !== '') {
            $catatan = trim($catatan . ($catatan !== '' ? "\n" : '') . "Pelapor (import): {$pelapor}");
        }

        $noStruk = $this->generateNoStruk('PNG', 'aset_penanganan', 'no_struk');

        AsetPenanganan::create([
            'aset_id' => $aset->id,
            'aset_pemakai_id' => null,
            'jenis_kerusakan' => $jenisKerusakan,
            'keluhan' => $keluhan,
            'tanggal_lapor' => $tanggalLapor,
            'lapor_at' => $tanggalLapor,
            'tanggal_diterima' => $tanggalDiterima,
            'diterima_at' => $tanggalDiterima,
            'tanggal_selesai' => $tanggalSelesai,
            'selesai_at' => $tanggalSelesai,
            'harga_jasa' => $hargaJasa,
            'biaya_komponen' => $biayaKomponen,
            'hasil' => $hasil,
            'no_struk' => $noStruk,
            'catatan' => $catatan !== '' ? $catatan : null,
        ]);

        if ($hasil === 'rusak_berat') {
            $aset->update(['status' => 'rusak_berat']);

            // sama seperti AsetPenangananController::update(): tutup paksa
            // pemakaian yang masih aktif biar "Dipakai Oleh" & riwayat
            // peminjaman ikut konsisten begitu aset dinyatakan rusak berat.
            AsetPemakai::where('aset_id', $aset->id)
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->update([
                    'tanggal_pengembalian' => $tanggalSelesai,
                    'dikembalikan_at' => now(),
                    'catatan_pengembalian' => 'Dikembalikan otomatis — aset dinyatakan rusak berat (import).',
                ]);
        }

        return true;
    }

    private function normalisasiHeader(string $header): string
    {
        $header = trim(strtolower($header));
        $header = preg_replace('/[\s\-\/]+/', '_', $header);
        $header = preg_replace('/[^a-z0-9_]/', '', $header);
        return trim($header, '_');
    }

    /**
     * Sama persis logicnya dengan AsetBuktiRapiImport::parseTanggal() --
     * tanggal teks format Indonesia (DD/MM/YYYY) DIPAKSA diparse duluan
     * sebelum fallback ke Carbon::parse(), supaya "06/01/2026" gak salah
     * kebaca gaya Amerika (Juni 1, padahal maksudnya 6 Januari).
     */
    private function parseTanggal($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }

        $teks = trim((string) $value);

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $teks, $m)) {
            return \Carbon\Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->format('Y-m-d');
        }

        try {
            return \Carbon\Carbon::parse($teks)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getRowCount()
    {
        return $this->rowCount;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}