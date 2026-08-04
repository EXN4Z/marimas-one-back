<?php

namespace App\Imports;

use App\Models\Aset;
use App\Models\JenisAset;
use App\Models\Supplier;
use App\Models\KelengkapanMaster;
use App\Models\AsetKelengkapan;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AsetImport implements ToCollection, WithHeadingRow
{
    protected $rowCount = 0;
    protected $errors = [];

    public function collection(Collection $rows)
    {
        if ($rows->isNotEmpty()) {
        Log::info('Header Excel terbaca:', array_keys($rows->first()->toArray()));
        }
        foreach ($rows as $index => $rawRow) {
            try {
                // BARU: normalisasi key tiap baris sebelum dipakai. Ini penting
                // karena header di file Excel sering ditulis dengan spasi
                // (mis. "No Good Receive", "Nama Kelengkapan") padahal kode
                // di bawah butuh key snake_case ("no_good_receive", dst).
                // Dengan ini, apapun variasi spasi/kapitalisasi header-nya,
                // hasilnya selalu konsisten.
                $row = collect($rawRow->toArray())
                    ->mapWithKeys(fn ($value, $key) => [$this->normalisasiHeader((string) $key) => $value])
                    ->toArray();

                // 1. Lookup / auto-create Jenis Aset
                $jenis = JenisAset::firstOrCreate(
                    ['nama' => trim($row['jenis_aset'] ?? $row['jenis'] ?? '')]
                );

                // 2. Lookup / auto-create Supplier
                $supplier = Supplier::firstOrCreate(
                    ['nama' => trim($row['supplier'])]
                );

                // 3. Simpan / update data Aset
                //    pakai serial_number sebagai penanda unik
                $aset = Aset::updateOrCreate(
                    ['serial_number' => $row['serial_number']],
                    [
                        'jenis_id'          => $jenis->id,
                        'supplier_id'       => $supplier->id,
                        'merek'             => $row['merek'],
                        'tipe'              => $row['tipe'],
                        'warna'             => $row['warna'],
                        'tanggal_garansi'   => $this->parseTanggal($row['tanggal_garansi']),
                        'tanggal_pembelian' => $this->parseTanggal($row['tanggal_pembelian']),
                        'perusahaan'        => $row['perusahaan'],
                        'no_surat_jalan'    => $row['no_surat_jalan'],
                        'no_good_receive'   => $row['no_good_receive'],
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
                $this->errors[] = "Baris " . ($index + 2) . ": " . $e->getMessage();
            }
        }
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