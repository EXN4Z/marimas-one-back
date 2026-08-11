<?php

namespace App\Imports;

use App\Models\Aset;
use App\Models\JenisAset;
use App\Models\Supplier;
use App\Models\KelengkapanMaster;
use App\Models\AsetKelengkapan;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * PENTING soal struktur file: file export "Data Aset" sekarang punya
 * baris banner judul + subjudul info filter di bagian atas SEBELUM baris
 * header kolom asli ("Jenis Aset", "Merek", dst). Jumlah baris banner ini
 * bisa berubah kapan saja kalau desain export-nya diubah lagi.
 *
 * Makanya di sini SENGAJA tidak pakai WithHeadingRow (yang butuh nomor
 * baris header di-hardcode). Sebagai gantinya, class ini scan beberapa
 * baris pertama sendiri untuk MENEMUKAN baris mana yang berisi header
 * kolom asli — dicek dari keberadaan kolom "serial_number" (kolom yang
 * pasti selalu ada & unik, jadi penanda paling aman). Apapun jumlah baris
 * banner di atasnya, kode ini tetap jalan tanpa perlu diubah manual lagi.
 */
class AsetImport implements ToCollection
{
    protected $rowCount = 0;
    protected $errors = [];

    /** Maksimal berapa baris awal yang discan buat nyari baris header */
    private const MAX_BARIS_DISCAN = 10;

    public function collection(Collection $rows)
    {
        $indexHeader = $this->cariBarisHeader($rows);

        if ($indexHeader === null) {
            $this->errors[] = 'Tidak menemukan baris header (kolom "Serial Number") di ' . self::MAX_BARIS_DISCAN . ' baris pertama. Pastikan file masih punya kolom Serial Number.';
            return;
        }

        $headers = $rows[$indexHeader]
            ->map(fn ($h) => $this->normalisasiHeader((string) $h))
            ->toArray();

        Log::info('Header Excel terbaca:', $headers);

        // Baris data dimulai tepat setelah baris header
        $dataRows = $rows->slice($indexHeader + 1);

        foreach ($dataRows as $index => $rawRow) {
            try {
                $rowArray = $rawRow->toArray();

                // Lewati baris yang benar-benar kosong semua (mis. baris pemisah/kosong di akhir file)
                if (count(array_filter($rowArray, fn ($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }

                // Gabungkan header hasil scan dengan isi baris data ini
                $row = array_combine(
                    $headers,
                    array_pad($rowArray, count($headers), null)
                );

                if (empty($row['serial_number'])) {
                    continue;
                }

                // 1. Lookup / auto-create Jenis Aset
                $jenis = JenisAset::firstOrCreate(
                    ['nama' => trim($row['jenis_aset'] ?? $row['jenis'] ?? '')]
                );

                // 2. Lookup / auto-create Supplier
                $supplier = Supplier::firstOrCreate(
                    ['nama' => trim($row['supplier'] ?? '')]
                );

                // 3. Simpan / update data Aset
                //    pakai serial_number sebagai penanda unik
                $aset = Aset::updateOrCreate(
                    ['serial_number' => $row['serial_number']],
                    [
                        'jenis_id'          => $jenis->id,
                        'supplier_id'       => $supplier->id,
                        'merek'             => $row['merek'] ?? null,
                        'tipe'              => $row['tipe'] ?? null,
                        'warna'             => $row['warna'] ?? null,
                        'tanggal_garansi'   => $this->parseTanggal($row['tanggal_garansi'] ?? null),
                        'tanggal_pembelian' => $this->parseTanggal($row['tanggal_pembelian'] ?? null),
                        'perusahaan'        => $row['perusahaan'] ?? null,
                        'no_surat_jalan'    => $row['no_surat_jalan'] ?? null,
                        'no_good_receive'   => $row['no_good_receive'] ?? null,
                        'status'            => $this->normalisasiStatus($row['status'] ?? null),
                    ]
                );

                // 4. Lookup / auto-create Kelengkapan Master
                //    PENTING: satu SEL Excel bisa berisi beberapa kelengkapan
                //    sekaligus, dipisah koma (mis. "Charger, Tas"). Kalau
                //    langsung disimpan apa adanya, itu jadi 1 nama master
                //    yang salah ("Charger, Tas" dianggap 1 barang). Jadi di
                //    sini kita pecah dulu berdasarkan koma, baru tiap item
                //    dibikinkan master + relasinya masing-masing.
                //
                //    "keterangan" diasumsikan berpasangan urut dengan
                //    "kelengkapan" (item ke-1 keterangan ke-1, dst). Kalau
                //    jumlahnya nggak match, keterangan yang sama dipakai
                //    buat semua item (fallback aman, tidak salah pasangan).
                if (!empty($row['kelengkapan'])) {
                    $daftarKelengkapan = array_filter(array_map('trim', explode(',', $row['kelengkapan'])));
                    $daftarKeterangan = !empty($row['keterangan'])
                        ? array_map('trim', explode(',', $row['keterangan']))
                        : [];

                    $keteranganBerpasangan = count($daftarKeterangan) === count($daftarKelengkapan);

                    foreach (array_values($daftarKelengkapan) as $i => $namaKelengkapan) {
                        $kelengkapanMaster = KelengkapanMaster::firstOrCreate(
                            ['nama' => $namaKelengkapan]
                        );

                        $keteranganItem = $keteranganBerpasangan
                            ? ($daftarKeterangan[$i] ?? null)
                            : ($row['keterangan'] ?? null);

                        // 5. Simpan relasi ke aset_kelengkapan
                        //    catatan: satu aset bisa punya beberapa baris kelengkapan yang sama,
                        //    jadi di sini pakai create biasa (bukan updateOrCreate),
                        //    kecuali kamu mau tiap aset+kelengkapan itu unik (lihat catatan di bawah)
                        AsetKelengkapan::create([
                            'aset_id'               => $aset->id,
                            'kelengkapan_master_id' => $kelengkapanMaster->id,
                            'keterangan'            => $keteranganItem,
                        ]);
                    }
                }

                $this->rowCount++;
            } catch (\Exception $e) {
                $this->errors[] = 'Baris data ke-' . ($index + 1) . ': ' . $e->getMessage();
            }
        }
    }

    /**
     * Scan MAX_BARIS_DISCAN baris pertama, cari baris yang salah satu
     * selnya (setelah dinormalisasi) persis "serial_number". Baris itulah
     * yang dianggap baris header kolom asli. Return null kalau nggak
     * ketemu sampai batas baris yang discan.
     */
    private function cariBarisHeader(Collection $rows): ?int
    {
        $batas = min(self::MAX_BARIS_DISCAN, $rows->count());

        for ($i = 0; $i < $batas; $i++) {
            $selDinormalisasi = $rows[$i]->map(fn ($v) => $this->normalisasiHeader((string) $v));

            if ($selDinormalisasi->contains('serial_number')) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Ubah nama kolom jadi format snake_case yang konsisten.
     * Contoh: "No Good Receive" -> "no_good_receive"
     *         "Nama  Kelengkapan" -> "nama_kelengkapan" (spasi ganda pun aman)
     *         "No. Surat Jalan"  -> "no_surat_jalan"
     */
    private function normalisasiHeader(string $header): string
    {
        $header = trim($header);
        $header = strtolower($header);
        $header = preg_replace('/[\s\-]+/', '_', $header); // spasi/strip berturutan -> 1 underscore
        $header = preg_replace('/[^a-z0-9_]/', '', $header); // buang karakter selain huruf/angka/underscore
        $header = trim($header, '_'); // buang underscore nyasar di awal/akhir
        return $header;
    }

    /**
     * Mapping label status di Excel (apapun kapitalisasi/spasinya) ke value
     * enum yang dipakai di database. Kalau user isi "Tersedia", "TERSEDIA",
     * atau "tersedia", hasilnya tetap konsisten 'tersedia'.
     *
     * Daftar mapping ini ngikutin STATUS_LABEL yang dipakai di fitur export
     * (AsetExportModal.tsx) — kalau di sana nambah status baru, tambahin juga di sini.
     */
    private function normalisasiStatus(?string $value): string
    {
        if (empty($value)) {
            return 'tersedia';
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/[\s\-]+/', '_', $value);

        $mapLabel = [
            'tersedia'             => 'tersedia',
            'dipakai'              => 'dipakai',
            'menunggu_perbaikan'   => 'menunggu_perbaikan',
            'diperbaiki'           => 'diperbaiki',
            'sedang_diperbaiki'    => 'diperbaiki',
            'rusak_berat'          => 'rusak_berat',
            'dijual'               => 'dijual',
        ];

        return $mapLabel[$value] ?? 'tersedia';
    }

    private function parseTanggal($value)
    {
        if (empty($value)) return null;

        // Excel kadang kirim tanggal sebagai serial number, kadang sebagai string
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }

        return \Carbon\Carbon::parse($value)->format('Y-m-d');
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