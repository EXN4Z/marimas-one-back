<?php

namespace App\Imports;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\MasterData\Inventory;
use App\Models\MasterData\Kategori;
use App\Models\MasterData\Departemen;
use App\Models\MasterData\Supplier;
use App\Models\Transaksi\InventoryPemakai;
use App\Models\User;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryBuktiImport implements ToCollection
{
    use GeneratesStrukNumber;

    protected $rowCount = 0;
    protected $errors = [];

    private const MAX_BARIS_DISCAN = 10;
    private const KOLOM_PENANDA_HEADER = 'no_bukti';

    /**
     * REVISI (refactor kategori bebas): dulu di sini ada 2 konstanta nama
     * kategori ("Barang Utama"/"Kelengkapan") yang dipakai buat nge-lookup
     * kategori_id lewat Kategori::where('nama', ...)->firstOrFail(). Sejak
     * migration `reset_and_seed_kategori_baru` (Fase 0 refactor kategori
     * bebas), 2 baris kategori itu SUDAH DIHAPUS dari tabel `kategori` --
     * jadi kode lama itu bakal selalu throw ModelNotFoundException tiap
     * baris diimport (bug, sudah kejadian, sekarang difix).
     *
     * Excel sumber file bukti serah-terima gak punya kolom "Kategori" sama
     * sekali -- struktur induk/kelengkapan cuma bisa ditentukan dari POSISI
     * KOLOM (lihat NOMOR_KOLOM_ASET_UTAMA), bukan dari nama kategori. Karena
     * kategori sekarang cuma label bebas yang gak menentukan struktur data
     * apa pun, dan kita gak punya info kategori sebenarnya dari Excel-nya,
     * semua baris hasil import (barang utama maupun kelengkapan) untuk
     * sementara ditaruh di 1 kategori fallback ini (get-or-create, gak akan
     * pernah throw). Admin bisa recategorize manual belakangan lewat
     * halaman Inventory kalau perlu.
     */
    private const NAMA_KATEGORI_FALLBACK_IMPORT = 'Belum Dikategorikan';

    /**
     * Nomor kolom "Nama Barang N" yang dianggap BARANG UTAMA. Semua kolom
     * "Nama Barang" dengan nomor lain (2, 3, dst) otomatis dianggap
     * KELENGKAPAN dari barang utama terakhir yang diproses di baris yang
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

    /**
     * Nilai yang sering dipakai di Excel sumber buat nandain "tidak ada
     * data" di kolom Nama Barang (mis. sel cuma diisi "-" biar nggak
     * kosong secara visual / gampang ke-scroll), TAPI bukan nama barang
     * beneran. Tanpa ditangani khusus, nilai-nilai ini lolos empty() check
     * (string non-kosong) dan kebikin jadi baris Inventory dengan nama
     * literal "-" dsb. Dicocokkan case-insensitive & setelah di-trim
     * lewat namaBarangKosong().
     */
    private const NILAI_PLACEHOLDER_NAMA_KOSONG = ['-', '--', '---', 'n/a', 'na', '.', 'kosong'];

    /**
     * Cache id kategori fallback (NAMA_KATEGORI_FALLBACK_IMPORT) supaya gak
     * query/create ke tabel kategori berulang-ulang tiap baris/kolom yang
     * diproses dalam satu proses import. Diisi lazy lewat kategoriIdFallback().
     */
    private ?int $kategoriIdFallbackCache = null;

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

        Log::info('Header Excel Bukti Inventory terbaca:', $headers);

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
                                // email diisi dummy unik. Password dibuat dari
                                // nama (huruf kecil, spasi jadi underscore), bukan
                                // random, biar user hasil import juga bisa login.
                                $penerimaUser = User::create([
                                    'name'          => $namaPenerima,
                                    'email'         => 'nik' . $nikPenerima . '@placeholder.local',
                                    'password'      => explode(' ', trim($namaPenerima))[0],
                                    'role'          => 'karyawan',
                                    'nik'           => $nikPenerima,
                                    'departemen_id' => $departemenId,
                                ]);
                            }
                        } else {
                            $this->errors[] = 'Baris data ke-' . ($index + 1) . ': ada nama penerima ("' . $namaPenerima . '") tapi NIK kosong, inventory dibuat tanpa data pemakai.';
                        }
                    }

                    $statusInventory = $penerimaUser ? 'dipakai' : 'tersedia';

                    $adaBarangDiproses = false;

                    // Barang utama terakhir yang berhasil dibuat di baris ini
                    // (parent_id = null) -- barang di kolom Nama Barang N
                    // (N != NOMOR_KOLOM_ASET_UTAMA) yang muncul SETELAHNYA
                    // jadi baris Inventory dengan parent_id nunjuk ke sini,
                    // dan status-nya ngikutin baris ini (lihat
                    // buatInventoryKelengkapan()).
                    $indukTerakhir = null;

                    foreach ($nomorBarang as $n) {
                        $namaBarang = $row["nama_barang_{$n}"] ?? null;

                        if ($this->namaBarangKosong($namaBarang)) {
                            continue;
                        }

                        $adaBarangDiproses = true;
                        $namaBarangTrim = trim($namaBarang);
                        $keteranganAsli = $row["keterangan_{$n}"] ?? null;

                        // Kolom Nama Barang selain kolom barang utama (mis.
                        // Nama Barang 2, 3, dst) SELALU dianggap kelengkapan
                        // dari barang utama terakhir yang sudah dibuat di
                        // baris yang sama -- ditentukan murni dari POSISI
                        // KOLOM, bukan dari kata dalam namanya.
                        if ($n !== self::NOMOR_KOLOM_ASET_UTAMA) {
                            if ($indukTerakhir) {
                                $kelengkapan = $this->buatInventoryKelengkapan(
                                    $indukTerakhir,
                                    $infoBukti,
                                    $supplierId,
                                    $namaBarangTrim,
                                    $keteranganAsli
                                );

                                if ($penerimaUser) {
                                    $this->buatInventoryPemakai($kelengkapan, $penerimaUser, $infoBukti['tanggal']);
                                }
                            } else {
                                // Kolom kelengkapan terisi tapi kolom barang
                                // utama (Nama Barang 1) di baris ini kosong --
                                // tidak ada induk buat dijadikan acuan
                                // status/parent_id, jadi dilewati (dicatat
                                // sebagai warning, bukan bikin baris
                                // kelengkapan tanpa induk).
                                $this->errors[] = 'Baris data ke-' . ($index + 1) . ': barang "' . $namaBarangTrim . '" (Nama Barang ' . $n . ') dilewati karena kolom Nama Barang ' . self::NOMOR_KOLOM_ASET_UTAMA . ' (barang utama) di baris yang sama kosong.';
                            }
                            continue;
                        }

                        // Jenis Aset (enum lama) sudah dihapus. Struktur
                        // induk/kelengkapan sekarang murni dari parent_id
                        // (ditentukan dari posisi kolom, lihat komentar
                        // NOMOR_KOLOM_ASET_UTAMA), BUKAN dari kategori_id --
                        // kategori_id di sini cuma fallback karena Excel
                        // sumber gak punya info kategori sebenarnya (lihat
                        // komentar NAMA_KATEGORI_FALLBACK_IMPORT). Nama
                        // barang ("Laptop", "Modem Telkomsel", dst) disimpan
                        // seragam ke kolom `nama`, sama seperti baris
                        // kelengkapan, karena sekarang keduanya baris di
                        // tabel `inventory` yang sama.
                        $hasilParse = $this->parseKeterangan($keteranganAsli);

                        $inventory = Inventory::create(array_merge($infoBukti, [
                            'nama'              => $namaBarangTrim,
                            'kategori_id'       => $this->kategoriIdFallback(),
                            'parent_id'         => null,
                            'supplier_id'       => $supplierId,
                            'jumlah'            => $row["jumlah_{$n}"] ?? null,
                            'keterangan'        => $keteranganAsli,
                            'serial_number'     => $hasilParse['serial_number'],
                            'warna'             => $hasilParse['warna'],
                            'status'            => $statusInventory,
                            // Samain juga ke tanggal_pembelian (kolom yang
                            // dipakai dashboard "Tren Pembelian per Bulan").
                            // $infoBukti['tanggal'] cuma keisi ke kolom
                            // 'tanggal' (kolom bukti serah-terima), jadi
                            // tanpa ini tanggal_pembelian selalu null buat
                            // inventory hasil import.
                            'tanggal_pembelian' => $infoBukti['tanggal'] ?? null,
                        ]));

                        $indukTerakhir = $inventory;

                        if ($penerimaUser) {
                            $this->buatInventoryPemakai($inventory, $penerimaUser, $infoBukti['tanggal']);
                        }

                        // Nama kelengkapan yang ke-parse dari teks Keterangan
                        // (mis. "(charger, tas)") -- sama seperti kolom Nama
                        // Barang N lainnya, tiap nama jadi baris Inventory
                        // ber-kategori kelengkapan yang parent_id-nya
                        // nunjuk ke barang utama ini, status-nya ngikutin
                        // barang utama ini.
                        foreach ($hasilParse['kelengkapan'] as $namaKelengkapan) {
                            $kelengkapan = $this->buatInventoryKelengkapan(
                                $inventory,
                                $infoBukti,
                                $supplierId,
                                $namaKelengkapan,
                                null
                            );

                            if ($penerimaUser) {
                                $this->buatInventoryPemakai($kelengkapan, $penerimaUser, $infoBukti['tanggal']);
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
     * isi kolom Nama Barang selain kolom barang utama) jadi baris
     * `inventory` dengan parent_id nunjuk ke $indukInventory (kategori_id
     * cuma fallback, lihat NAMA_KATEGORI_FALLBACK_IMPORT) -- BUKAN
     * tabel/model terpisah lagi seperti dulu
     * (AsetKelengkapan sudah dihapus). $keterangan (kalau ada) di-parse
     * ulang lewat parseKeterangan() buat coba tarik serial_number & warna-
     * nya juga, sama seperti yang dilakukan buat barang utama. Info bukti
     * (perusahaan, tanggal) & supplier disamakan dengan induknya lewat
     * $infoBukti/$supplierId yang dioper dari caller. status-nya ikut
     * status induk saat baris ini diproses (bukan status 'tersedia'
     * hardcode).
     */
    private function buatInventoryKelengkapan(Inventory $indukInventory, array $infoBukti, ?int $supplierId, string $namaBarang, ?string $keterangan): Inventory
    {
        $hasilParse = $this->parseKeterangan($keterangan);

        return Inventory::create([
            'parent_id'         => $indukInventory->id,
            'kategori_id'       => $this->kategoriIdFallback(),
            'nama'              => $namaBarang,
            'warna'             => $hasilParse['warna'],
            'serial_number'     => $hasilParse['serial_number'],
            'keterangan'        => $keterangan,
            'supplier_id'       => $supplierId,
            'perusahaan'        => $infoBukti['perusahaan'] ?? null,
            'tanggal_pembelian' => $infoBukti['tanggal'] ?? null,
            'status'            => $indukInventory->status,
        ]);
    }

    /**
     * Buat 1 baris inventory_pemakai buat 1 baris Inventory (barang utama
     * ATAUPUN kelengkapan -- sekarang gak perlu dibedakan lagi karena
     * keduanya sama-sama baris tabel `inventory`, cukup 1 kolom
     * inventory_id, gak perlu lagi kolom aset_kelengkapan_id terpisah
     * seperti dulu). Sama seperti InventoryPemakaiController::store() --
     * setiap InventoryPemakai WAJIB punya no_struk_penerimaan sendiri
     * (unik per baris, di-generate ulang tiap panggilan), karena
     * kembalikan() nanti mencocokkan input no_struk_penerimaan persis
     * dengan kolom ini. Tanpa di-generate di sini, data hasil import
     * punya no_struk_penerimaan = null, dan barang itu jadi TIDAK BISA
     * PERNAH dikembalikan lewat endpoint kembalikan() (gak ada string
     * yang bisa cocok dengan null).
     *
     * 'diterima_at' SENGAJA tidak diisi now() -- beda dari store() yang
     * isi now() karena itu aksi live. Di sini datanya historis (dari
     * bukti serah-terima lama), jadi biarkan riwayat() fallback ke
     * tanggal_penerimaan (lihat komentar fallback *_at di riwayat())
     * supaya pengurutan waktu di Riwayat Inventory tetap benar sesuai
     * tanggal transaksi asli, bukan tanggal import dijalankan.
     */
    private function buatInventoryPemakai(Inventory $item, User $penerimaUser, ?string $tanggalPenerimaan): void
    {
        $noStruk = $this->generateNoStruk('STJ', 'inventory_pemakai', 'no_struk_penerimaan');

        InventoryPemakai::create([
            'inventory_id'        => $item->id,
            'user_id'             => $penerimaUser->id,
            'status'              => 'disetujui',
            'no_struk_penerimaan' => $noStruk,
            'tanggal_penerimaan'  => $tanggalPenerimaan,
            'diterima_at'         => $tanggalPenerimaan,
        ]);
    }

    /**
     * Ambil id kategori fallback (NAMA_KATEGORI_FALLBACK_IMPORT), bikin
     * kalau belum ada (firstOrCreate -- BEDA dari firstOrFail versi lama
     * yang throw kalau kategori gak ketemu). Ini kategori bebas biasa,
     * bukan lagi baris "spesial" yang divalidasi khusus di tempat lain,
     * jadi aman dibikin otomatis di sini.
     */
    private function kategoriIdFallback(): int
    {
        if ($this->kategoriIdFallbackCache === null) {
            $this->kategoriIdFallbackCache = Kategori::firstOrCreate(
                ['nama' => self::NAMA_KATEGORI_FALLBACK_IMPORT]
            )->id;
        }

        return $this->kategoriIdFallbackCache;
    }

    /**
     * True kalau isi kolom "Nama Barang N" harus dianggap TIDAK ADA data
     * beneran -- baik karena benar-benar kosong (null/''), maupun karena
     * cuma placeholder yang lazim dipakai di Excel sumber buat nandain
     * "sel ini sengaja gak diisi" (mis. "-"). Tanpa ini, nilai seperti "-"
     * lolos empty() check (string "-" itu non-empty di PHP) dan kepakai
     * apa adanya sebagai `nama` baris Inventory (barang utama maupun
     * kelengkapan), sehingga muncul barang dengan nama literal "-".
     */
    private function namaBarangKosong(?string $nama): bool
    {
        if (empty($nama)) {
            return true;
        }

        return in_array(strtolower(trim($nama)), self::NILAI_PLACEHOLDER_NAMA_KOSONG, true);
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