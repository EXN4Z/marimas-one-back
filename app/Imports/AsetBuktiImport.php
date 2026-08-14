<?php

namespace App\Imports;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
use App\Models\AsetKelengkapan;
use App\Models\AsetPemakai;
use App\Models\Departemen;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsetBuktiImport implements ToCollection
{
    use GeneratesStrukNumber;

    protected $rowCount = 0;
    protected $errors = [];

    private const MAX_BARIS_DISCAN = 10;
    private const KOLOM_PENANDA_HEADER = 'no_bukti';

    /**
     * Nomor kolom "Nama Barang N" yang dianggap ASET UTAMA. Semua kolom
     * "Nama Barang" dengan nomor lain (2, 3, dst) otomatis dianggap
     * KELENGKAPAN dari aset utama terakhir yang diproses di baris yang
     * sama -- terlepas dari isi namanya ("Charger", "Tas", "Modem",
     * apapun). Ini gantiin deteksi berbasis kata kunci (cocokAksesoris)
     * yang dulu dipakai, karena posisi kolom di Excel sumber sudah pasti
     * konsisten: kolom pertama = barang utama, kolom berikutnya = barang
     * yang menyertai/melengkapi barang utama itu.
     */
    private const NOMOR_KOLOM_ASET_UTAMA = 1;

    private const WARNA_KEYWORDS = [
        'black', 'white', 'silver', 'grey', 'gray', 'blue', 'red', 'green',
        'yellow', 'pink', 'purple', 'gold', 'navy', 'cream', 'orange',
        'coklat', 'cokelat', 'putih', 'hitam', 'merah', 'biru', 'kuning',
        'hijau', 'ungu', 'abu-abu', 'abu',
    ];

    private const KETERANGAN_PREFIX_DIABAIKAN = '/^\s*(model|imei|p\s*\/?\s*n)\b/i';

    public function collection(Collection $rows)
    {
        $indexHeader = $this->cariBarisHeader($rows);

        if ($indexHeader === null) {
            $this->errors[] = 'Tidak menemukan baris header (kolom "No Bukti") di ' . self::MAX_BARIS_DISCAN . ' baris pertama.';
            return;
        }

        $headers = $rows[$indexHeader]
            ->map(fn ($h) => $this->normalisasiHeader((string) $h))
            ->toArray();

        Log::info('Header Excel Bukti Aset terbaca:', $headers);

        $nomorBarang = $this->cariNomorBarang($headers);

        if (empty($nomorBarang)) {
            $this->errors[] = 'Tidak menemukan kolom barang (pola "Nama Barang 1", "Nama Barang 2", dst).';
            return;
        }

        $dataRows = $rows->slice($indexHeader + 1);

        foreach ($dataRows as $index => $rawRow) {
            try {
                DB::transaction(function () use ($rawRow, $headers, $nomorBarang, $index) {
                    $rowArray = $rawRow->toArray();

                    if (count(array_filter($rowArray, fn ($v) => $v !== null && $v !== '')) === 0) {
                        return;
                    }

                    $row = array_combine(
                        $headers,
                        array_pad($rowArray, count($headers), null)
                    );

                    if (empty($row['no_bukti'])) {
                        return;
                    }

                    $namaDepartemen = trim((string) ($row['departemen'] ?? ''));
                    $departemenId = null;

                    if ($namaDepartemen !== '') {
                        $departemenId = Departemen::firstOrCreate(['nama' => $namaDepartemen])->id;
                    }

                    $infoBukti = [
                        'no_bukti'       => $row['no_bukti'],
                        'tanggal'        => $this->parseTanggal($row['tanggal'] ?? null),
                        'perusahaan'     => $row['perusahaan'] ?? null,
                        'departemen_id'  => $departemenId,
                        'nik'            => $row['nik'] ?? null,
                        'penerima'       => $row['penerima'] ?? null,
                        'diterima_oleh'  => $row['diterima_oleh'] ?? null,
                        'diketahui'      => $row['diketahui'] ?? null,
                        'dibuat_oleh'    => $row['dibuat_oleh'] ?? null,
                        'diketahui_hrd'  => $row['diketahui_hrd'] ?? null,
                    ];

                    $namaSupplier = trim((string) ($row['supplier'] ?? ''));
                    $supplierId = null;

                    if ($namaSupplier !== '') {
                        $supplierId = Supplier::firstOrCreate(['nama' => $namaSupplier])->id;
                    }

                    $namaPenerima = trim((string) ($row['penerima'] ?? ''));
                    $nikPenerima = trim((string) ($row['nik'] ?? ''));
                    $penerimaUser = null;

                    if ($namaPenerima !== '') {
                        if ($nikPenerima !== '') {
                            $penerimaUser = User::where('nik', $nikPenerima)->first();

                            if (!$penerimaUser) {
                                // Pekerja::create() dulu bikin row minimal tanpa akun
                                // login/password. Sekarang pekerja = users, jadi
                                // User::create() butuh email/password (kolom wajib) --
                                // diisi dummy unik & random, sama seperti resolusi
                                // NIK di atas.
                                $penerimaUser = User::create([
                                    'name'          => $namaPenerima,
                                    'email'         => 'nik' . $nikPenerima . '@placeholder.local',
                                    'password'      => Str::random(32),
                                    'role'          => 'karyawan',
                                    'nik'           => $nikPenerima,
                                    'departemen_id' => $departemenId,
                                ]);
                            }
                        } else {
                            $this->errors[] = 'Baris data ke-' . ($index + 1) . ': ada nama penerima ("' . $namaPenerima . '") tapi NIK kosong, aset dibuat tanpa data pemakai.';
                        }
                    }

                    $statusAset = $penerimaUser ? 'dipakai' : 'tersedia';

                    $adaBarangDiproses = false;

                    // Aset "utama" terakhir yang berhasil dibuat di baris ini
                    // -- barang di kolom Nama Barang N (N != NOMOR_KOLOM_ASET_UTAMA)
                    // yang muncul SETELAHNYA jadi baris AsetKelengkapan yang
                    // nempel ke aset ini, dan status-nya ngikutin aset ini
                    // (lihat buatAsetKelengkapan()).
                    $asetUtamaTerakhir = null;

                    foreach ($nomorBarang as $n) {
                        $namaBarang = $row["nama_barang_{$n}"] ?? null;

                        if (empty($namaBarang)) {
                            continue;
                        }

                        $adaBarangDiproses = true;
                        $namaBarangTrim = trim($namaBarang);
                        $keteranganAsli = $row["keterangan_{$n}"] ?? null;

                        // Kolom Nama Barang selain kolom aset utama (mis.
                        // Nama Barang 2, 3, dst) SELALU dianggap kelengkapan
                        // dari aset utama terakhir yang sudah dibuat di baris
                        // yang sama -- ditentukan murni dari POSISI KOLOM,
                        // bukan dari kata dalam namanya.
                        if ($n !== self::NOMOR_KOLOM_ASET_UTAMA) {
                            if ($asetUtamaTerakhir) {
                                $asetKelengkapan = $this->buatAsetKelengkapan(
                                    $asetUtamaTerakhir,
                                    $infoBukti,
                                    $supplierId,
                                    $namaBarangTrim,
                                    $keteranganAsli
                                );

                                if ($penerimaUser) {
                                    $this->buatAsetPemakai($asetKelengkapan, $penerimaUser, $infoBukti['tanggal']);
                                }
                            } else {
                                // Kolom kelengkapan terisi tapi kolom aset
                                // utama (Nama Barang 1) di baris ini kosong --
                                // tidak ada aset induk buat dijadikan acuan
                                // status/aset_id, jadi dilewati (dicatat
                                // sebagai warning, bukan bikin AsetKelengkapan
                                // tanpa aset induk).
                                $this->errors[] = 'Baris data ke-' . ($index + 1) . ': barang "' . $namaBarangTrim . '" (Nama Barang ' . $n . ') dilewati karena kolom Nama Barang ' . self::NOMOR_KOLOM_ASET_UTAMA . ' (aset utama) di baris yang sama kosong.';
                            }
                            continue;
                        }

                        // Jenis Aset sudah dihapus -- nama barang ("Laptop",
                        // "Modem Telkomsel", dst) sekarang disimpan ke
                        // `merek`, biar trigger kode_aset (yang ambil kata
                        // pertama dari merek) tetap dapet bahan generate.
                        $hasilParse = $this->parseKeterangan($keteranganAsli);

                        $aset = Aset::create(array_merge($infoBukti, [
                            'merek'         => $namaBarangTrim,
                            'supplier_id'   => $supplierId,
                            'jumlah'        => $row["jumlah_{$n}"] ?? null,
                            'keterangan'    => $keteranganAsli,
                            'serial_number' => $hasilParse['serial_number'],
                            'warna'         => $hasilParse['warna'],
                            'status'        => $statusAset,
                        ]));

                        $asetUtamaTerakhir = $aset;

                        if ($penerimaUser) {
                            $this->buatAsetPemakai($aset, $penerimaUser, $infoBukti['tanggal']);
                        }

                        // Nama kelengkapan yang ke-parse dari teks Keterangan
                        // (mis. "(charger, tas)") -- sama seperti kolom Nama
                        // Barang N lainnya, tiap nama jadi baris
                        // AsetKelengkapan yang nempel ke aset utama ini lewat
                        // aset_id, status-nya ngikutin aset utama ini.
                        foreach ($hasilParse['kelengkapan'] as $namaKelengkapan) {
                            $asetKelengkapan = $this->buatAsetKelengkapan(
                                $aset,
                                $infoBukti,
                                $supplierId,
                                $namaKelengkapan,
                                null
                            );

                            if ($penerimaUser) {
                                $this->buatAsetPemakai($asetKelengkapan, $penerimaUser, $infoBukti['tanggal']);
                            }
                        }
                    }

                    if (!$adaBarangDiproses) {
                        $this->errors[] = 'Baris data ke-' . ($index + 1) . ': tidak ada barang yang diisi.';
                        return;
                    }

                    $this->rowCount++;
                });
            } catch (\Exception $e) {
                $this->errors[] = 'Baris data ke-' . ($index + 1) . ': ' . $e->getMessage();
            }
        }
    }

    /**
     * Bikin 1 nama barang kelengkapan (mis. "Charger", "Tas", atau apapun
     * isi kolom Nama Barang selain kolom aset utama) jadi baris
     * AsetKelengkapan (tabel aset_kelengkapan) yang nempel ke aset induknya
     * lewat aset_id -- BUKAN baris Aset sendiri. $keterangan (kalau ada)
     * di-parse ulang lewat parseKeterangan() buat coba tarik serial_number
     * & warna-nya juga, sama seperti yang dilakukan buat aset utama. Info
     * bukti (perusahaan, tanggal) & supplier disamakan dengan aset induknya
     * lewat $infoBukti/$supplierId yang dioper dari caller, dan status-nya
     * ikut status aset induk saat baris ini diproses (bukan status
     * 'tersedia' hardcode).
     */
    private function buatAsetKelengkapan(Aset $asetInduk, array $infoBukti, ?int $supplierId, string $namaBarang, ?string $keterangan): AsetKelengkapan
    {
        $hasilParse = $this->parseKeterangan($keterangan);

        return AsetKelengkapan::create([
            'aset_id'           => $asetInduk->id,
            'nama'              => $namaBarang,
            'warna'             => $hasilParse['warna'],
            'serial_number'     => $hasilParse['serial_number'],
            'keterangan'        => $keterangan,
            'supplier_id'       => $supplierId,
            'perusahaan'        => $infoBukti['perusahaan'] ?? null,
            'tanggal_pembelian' => $infoBukti['tanggal'] ?? null,
            'status'            => $asetInduk->status,
        ]);
    }

    /**
     * Buat 1 baris aset_pemakai buat 1 barang (Aset utama ATAUPUN
     * AsetKelengkapan). Tabel aset_pemakai punya kolom aset_id DAN
     * aset_kelengkapan_id -- cuma salah satunya yang diisi tergantung tipe
     * $item, yang lain dibiarkan null. Sama seperti
     * AsetPemakaiController::store() -- setiap AsetPemakai WAJIB punya
     * no_struk_penerimaan sendiri (unik per baris, di-generate ulang tiap
     * panggilan), karena kembalikan() nanti mencocokkan input
     * no_struk_penerimaan persis dengan kolom ini. Tanpa di-generate di
     * sini, data hasil import punya no_struk_penerimaan = null, dan aset
     * itu jadi TIDAK BISA PERNAH dikembalikan lewat endpoint kembalikan()
     * (gak ada string yang bisa cocok dengan null).
     *
     * 'diterima_at' SENGAJA tidak diisi (dibiarkan null) -- beda dari
     * store() yang isi now() karena itu aksi live. Di sini datanya
     * historis (dari bukti serah-terima lama), jadi biarkan riwayat()
     * fallback ke tanggal_penerimaan (lihat komentar fallback *_at di
     * riwayat()) supaya pengurutan waktu di Riwayat Aset tetap benar
     * sesuai tanggal transaksi asli, bukan tanggal import dijalankan.
     */
    private function buatAsetPemakai(Aset|AsetKelengkapan $item, User $penerimaUser, ?string $tanggalPenerimaan): void
    {
        $noStruk = $this->generateNoStruk('STJ', 'aset_pemakai', 'no_struk_penerimaan');

        AsetPemakai::create([
            'aset_id'             => $item instanceof Aset ? $item->id : null,
            'aset_kelengkapan_id' => $item instanceof AsetKelengkapan ? $item->id : null,
            'user_id'             => $penerimaUser->id,
            'status'              => 'disetujui',
            'no_struk_penerimaan' => $noStruk,
            'tanggal_penerimaan'  => $tanggalPenerimaan,
        ]);
    }

    private function cariNomorBarang(array $headers): array
    {
        $nomor = [];

        foreach ($headers as $header) {
            if (preg_match('/^nama_barang_(\d+)$/', $header, $match)) {
                $nomor[] = (int) $match[1];
            }
        }

        sort($nomor);

        return $nomor;
    }

    private function cariBarisHeader(Collection $rows): ?int
    {
        $batas = min(self::MAX_BARIS_DISCAN, $rows->count());

        for ($i = 0; $i < $batas; $i++) {
            $selDinormalisasi = $rows[$i]->map(fn ($v) => $this->normalisasiHeader((string) $v));

            if ($selDinormalisasi->contains(self::KOLOM_PENANDA_HEADER)) {
                return $i;
            }
        }

        return null;
    }

    private function normalisasiHeader(string $header): string
    {
        $header = trim($header);
        $header = strtolower($header);
        $header = preg_replace('/[\s\-]+/', '_', $header);
        $header = preg_replace('/[^a-z0-9_]/', '', $header);
        $header = trim($header, '_');
        return $header;
    }

    private function parseKeterangan(?string $teks): array
    {
        $hasil = ['serial_number' => null, 'warna' => null, 'kelengkapan' => []];

        if (empty(trim((string) $teks))) {
            return $hasil;
        }

        $sisaTeks = (string) $teks;

        if (preg_match('/\bS\s*\/?\s*N\s*:?\s*([A-Za-z0-9\-]+)/i', $sisaTeks, $match, PREG_OFFSET_CAPTURE)) {
            $hasil['serial_number'] = trim($match[1][0]);
            $panjangMatch = strlen($match[0][0]);
            $posisiMatch = $match[0][1];
            $sisaTeks = substr($sisaTeks, 0, $posisiMatch) . substr($sisaTeks, $posisiMatch + $panjangMatch);
        }

        if (preg_match_all('/\(([^)]*)\)/', $sisaTeks, $matchKurung)) {
            foreach ($matchKurung[1] as $isiKurung) {
                $hasil['kelengkapan'] = array_merge($hasil['kelengkapan'], $this->pisahDaftar($isiKurung));
            }
            $sisaTeks = preg_replace('/\(([^)]*)\)/', '', $sisaTeks);
        }

        foreach ($this->pisahDaftar($sisaTeks) as $token) {
            if (preg_match(self::KETERANGAN_PREFIX_DIABAIKAN, $token)) {
                continue;
            }

            if ($hasil['warna'] === null && preg_match('/\b(' . implode('|', self::WARNA_KEYWORDS) . ')\b/i', $token)) {
                $hasil['warna'] = $token;
                continue;
            }

            $hasil['kelengkapan'][] = $token;
        }

        return $hasil;
    }

    private function pisahDaftar(string $teks): array
    {
        $hasil = [];

        foreach (preg_split('/[;,]/', $teks) as $bagian) {
            foreach (preg_split('/\s+dan\s+/i', $bagian) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $hasil[] = $token;
                }
            }
        }

        return $hasil;
    }

    private function parseTanggal($value)
    {
        if (empty($value)) return null;

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