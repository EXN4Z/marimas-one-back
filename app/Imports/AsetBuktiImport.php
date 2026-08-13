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

    private const WARNA_KEYWORDS = [
        'black', 'white', 'silver', 'grey', 'gray', 'blue', 'red', 'green',
        'yellow', 'pink', 'purple', 'gold', 'navy', 'cream', 'orange',
        'coklat', 'cokelat', 'putih', 'hitam', 'merah', 'biru', 'kuning',
        'hijau', 'ungu', 'abu-abu', 'abu',
    ];

    private const KETERANGAN_PREFIX_DIABAIKAN = '/^\s*(model|imei|p\s*\/?\s*n)\b/i';

    /**
     * Kata kunci buat mendeteksi "Nama Barang N" yang sebenarnya bukan
     * barang utama, tapi AKSESORIS dari barang utama di baris yang sama --
     * misalnya di 1 baris bukti ada "Nama Barang 1: Laptop", "Nama Barang
     * 2: Charger", "Nama Barang 3: Tas". Laptop tetap jadi baris Aset baru
     * seperti biasa (merek diisi dari nama barang ini). Charger & Tas
     * dibikinkan baris AsetKelengkapan (tabel aset_kelengkapan) yang
     * nempel ke Laptop lewat aset_id -- bukan lagi baris Aset sendiri.
     * Kode unik, S/N kalau ada, dan status-nya ngikutin barang utama
     * TERAKHIR yang sudah diproses di baris yang sama (lihat
     * cocokAksesoris() & buatAsetKelengkapan() di bawah).
     *
     * Dicocokkan pakai WORD-BOUNDARY, case-insensitive (lihat
     * cocokAksesoris()) -- BUKAN substring polos. Kata kunci pendek
     * seperti "dus" atau "tas" gampang nyangkut ke potongan kata lain
     * kalau dicocokkan sebagai substring (mis. "dus" nyempil di
     * "Modem Telkomsel Orbit ex SP Kudus"), jadi harus dicocokkan sebagai
     * kata utuh. Kalau nanti ada kata kunci aksesoris lain yang perlu
     * ditambah (mis. "mouse", "sarung", "softcase"), tinggal tambah di
     * sini -- gak perlu ubah logic lain.
     */
    private const KATA_KUNCI_AKSESORIS = [
        'charger', 'adaptor', 'adapter', 'tas', 'dus', 'case', 'baterai', 'kabel',
    ];

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
                    // -- barang aksesoris (charger, tas, dst) yang muncul
                    // SETELAHNYA di kolom Nama Barang N yang lain jadi baris
                    // AsetKelengkapan yang nempel ke aset ini, dan status-nya
                    // ngikutin aset ini (lihat buatAsetKelengkapan()).
                    $asetUtamaTerakhir = null;

                    foreach ($nomorBarang as $n) {
                        $namaBarang = $row["nama_barang_{$n}"] ?? null;

                        if (empty($namaBarang)) {
                            continue;
                        }

                        $adaBarangDiproses = true;
                        $namaBarangTrim = trim($namaBarang);
                        $keteranganAsli = $row["keterangan_{$n}"] ?? null;

                        // Barang ini kelengkapan (charger/tas/dst) -- bikin
                        // sebagai baris AsetKelengkapan yang nempel ke aset
                        // utama terakhir yang sudah dibuat di baris yang
                        // sama, lalu lanjut ke kolom Nama Barang berikutnya.
                        if ($this->cocokAksesoris($namaBarangTrim)) {
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
                                // Aksesoris muncul duluan sebelum ada barang
                                // utama di baris ini -- tidak ada aset induk
                                // buat dijadiin acuan status/aset_id, jadi
                                // dilewati (dicatat sebagai warning, bukan
                                // bikin AsetKelengkapan tanpa aset induk).
                                $this->errors[] = 'Baris data ke-' . ($index + 1) . ': barang aksesoris "' . $namaBarangTrim . '" (Nama Barang ' . $n . ') dilewati karena belum ada barang utama di baris yang sama untuk dijadikan acuan status.';
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
                        // (mis. "(charger, tas)") -- sama seperti aksesoris di
                        // kolom Nama Barang N, tiap nama jadi baris
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
     * Cek apakah 1 nama barang (dari kolom "Nama Barang N") itu aksesoris
     * (charger, tas, dus, dst -- lihat KATA_KUNCI_AKSESORIS), bukan jenis
     * barang utama. Cocok pakai WORD-BOUNDARY (bukan substring polos),
     * biar kata seperti "dus" tidak ikut kena kalau cuma nyempil di dalam
     * kata lain (mis. "Modem Telkomsel Orbit ex SP Kudus" -- "dus" di
     * situ bagian dari "Kudus", bukan kata "dus" yang berarti kardus).
     */
    private function cocokAksesoris(string $namaBarang): bool
    {
        $namaLower = mb_strtolower($namaBarang);

        foreach (self::KATA_KUNCI_AKSESORIS as $kataKunci) {
            if (preg_match('/\b' . preg_quote($kataKunci, '/') . '\b/u', $namaLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bikin 1 nama barang kelengkapan (mis. "Charger", "Tas") jadi baris
     * AsetKelengkapan (tabel aset_kelengkapan) yang nempel ke aset induknya
     * lewat aset_id -- BUKAN baris Aset sendiri. $keterangan (kalau ada) di-parse ulang lewat
     * parseKeterangan() buat coba tarik serial_number & warna-nya juga,
     * sama seperti yang dilakukan buat aset utama. Info bukti (perusahaan,
     * tanggal) & supplier disamakan dengan aset induknya lewat
     * $infoBukti/$supplierId yang dioper dari caller, dan status-nya ikut
     * status aset induk saat baris ini diproses (bukan status 'tersedia'
     * hardcode).
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