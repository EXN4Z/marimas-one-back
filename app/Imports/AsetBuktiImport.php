<?php

namespace App\Imports;

use App\Models\Aset;
use App\Models\AsetKelengkapan;
use App\Models\AsetPemakai;
use App\Models\Departemen;
use App\Models\JenisAset;
use App\Models\KelengkapanMaster;
use App\Models\Pekerja;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Import "Bukti Serah Terima/Peminjaman Barang" dari Excel LANGSUNG ke
 * tabel `aset` (BUKAN tabel terpisah). Beda dengan AsetImport (yang untuk
 * data aset detail per unit dengan serial_number sebagai kunci unik),
 * format ini dipakai untuk mendaftarkan aset baru dari 1 bukti yang bisa
 * berisi SAMPAI 4 BARANG BERBEDA sekaligus dalam 1 baris Excel:
 *   "Nama Barang 1/Jumlah 1/Keterangan 1", "... 2", "... 3", "... 4"
 *
 * Tiap "Nama Barang N" yang keisi -> jadi 1 baris baru di tabel `aset`.
 * Info "Diterima Oleh", "Diketahui", "Dibuat Oleh", "diketahui hrd" itu
 * SAMA untuk keempat aset dari baris yang sama (bukan per-barang), jadi
 * disalin ke tiap baris Aset yang dibuat dari bukti tersebut.
 *
 * PENTING: karena format ini TIDAK punya kolom serial_number, baris di
 * sini SELALU dibuat sebagai Aset BARU (pakai create(), bukan
 * updateOrCreate() seperti AsetImport) -- tidak ada cara aman untuk
 * mendeteksi "ini aset yang sama dengan yang sudah pernah diimport",
 * jadi import ulang file yang sama akan menghasilkan Aset duplikat.
 *
 * Kolom barang tidak dihardcode ke 4 -- kode ini scan header untuk semua
 * pola "nama_barang_N" secara otomatis, jadi kalau nanti nambah jadi
 * "Nama Barang 5", "Jumlah 5", "Keterangan 5" di Excel, tinggal jalan
 * tanpa perlu ubah kode.
 *
 * CATATAN: format bukti serah terima ini biasanya TIDAK punya kolom
 * "Supplier" (beda dengan format AsetImport). Kalau kolom supplier tidak
 * ada / kosong di baris, supplier_id sengaja dibiarkan null -- bukan
 * bikin Supplier baru dengan nama kosong.
 *
 * PARSING KETERANGAN: kolom "Keterangan N" di Excel ini isinya teks bebas
 * yang sebenarnya menggabungkan 3 info sekaligus, misalnya:
 *   "Ocean Blue, Model VS18107, S/N : W20213901337 (remote, adaptor, kabel HDMI)"
 * -> warna: "Ocean Blue", serial_number: "W20213901337",
 *    kelengkapan: ["remote", "adaptor", "kabel HDMI"]
 * Method parseKeterangan() mengekstrak ini secara heuristik (cari pola
 * S/N atau SN, cari kata warna dari daftar kata kunci, sisanya + isi
 * dalam kurung dianggap kelengkapan, dipisah per koma/titik-koma/"dan").
 * Teks "Model ...", "Imei ...", "P/N ..." SENGAJA tidak diikutkan ke
 * warna/kelengkapan (supaya tidak salah tebak) -- tapi teks keterangan
 * ASLI tetap disimpan utuh di kolom `keterangan`, jadi tidak ada info
 * yang hilang, cuma yang berhasil dikenali saja yang dijadikan data
 * terstruktur (serial_number, warna, aset_kelengkapan).
 * Karena ini heuristik atas teks bebas manusia, tidak dijamin 100% akurat
 * untuk semua kemungkinan format baru -- kalau ada pola keterangan baru
 * yang tidak tertangkap, cek kolom `keterangan` aslinya lalu sesuaikan
 * WARNA_KEYWORDS / pola regex di bawah.
 *
 * PENERIMA / PEKERJA / ASET_PEMAKAI: kolom "NIK" & "Penerima" di Excel
 * dipakai untuk mencari (atau membuat) Pekerja penerima aset. NIK
 * sementara disimpan di kolom `nip` tabel `pekerja` (nama kolom belum
 * diganti). Kalau User untuk NIK itu belum ada, User + Pekerja baru
 * otomatis dibuat (role default 'karyawan', email placeholder karena
 * format Excel ini tidak punya kolom email). Kalau penerima berhasil
 * diresolusi, status Aset yang dibuat jadi "dipakai" (bukan "tersedia")
 * dan dibuatkan juga 1 baris AsetPemakai per Aset, dengan kolom yang
 * datanya tidak tersedia dari Excel dibiarkan null.
 */
class AsetBuktiImport implements ToCollection
{
    protected $rowCount = 0;
    protected $errors = [];

    /** Maksimal berapa baris awal yang discan buat nyari baris header */
    private const MAX_BARIS_DISCAN = 10;

    /** Kolom penanda baris header -- "No Bukti" dipilih karena khas & selalu ada */
    private const KOLOM_PENANDA_HEADER = 'no_bukti';

    /** Kata kunci warna (ID + EN) buat deteksi token warna di teks keterangan */
    private const WARNA_KEYWORDS = [
        'black', 'white', 'silver', 'grey', 'gray', 'blue', 'red', 'green',
        'yellow', 'pink', 'purple', 'gold', 'navy', 'cream', 'orange',
        'coklat', 'cokelat', 'putih', 'hitam', 'merah', 'biru', 'kuning',
        'hijau', 'ungu', 'abu-abu', 'abu',
    ];

    /** Prefix token yang SENGAJA tidak dianggap warna maupun kelengkapan */
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
                // Setiap baris diproses dalam transaction sendiri-sendiri.
                // PENTING di Postgres: begitu 1 query di dalam sebuah
                // transaction gagal (mis. melanggar CHECK constraint),
                // SELURUH transaction itu langsung "aborted" dan semua
                // query berikutnya di transaction yang sama ikut ditolak
                // (25P02) walau datanya sendiri valid. Kalau semua baris
                // dari 1 file dibungkus dalam 1 transaction besar, 1 baris
                // gagal akan bikin SEMUA baris sesudahnya ikut gagal.
                // Makanya di sini tiap baris punya transaction terpisah:
                // baris gagal di-rollback sendiri, baris lain tetap lanjut.
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

                    // Tabel `aset` tidak punya kolom teks "departemen" -- yang
                    // ada cuma `departemen_id` (foreign key ke tabel
                    // `departemen`). Sama seperti Supplier di bawah, nama
                    // departemen dari Excel di-firstOrCreate supaya otomatis
                    // kebuat kalau belum ada, lalu dipakai id-nya.
                    $namaDepartemen = trim((string) ($row['departemen'] ?? ''));
                    $departemenId = null;

                    if ($namaDepartemen !== '') {
                        $departemenId = Departemen::firstOrCreate(['nama' => $namaDepartemen])->id;
                    }

                    // Info bukti yang sama dipakai berulang untuk tiap barang
                    // di baris ini (bukan per-barang, tapi per-bukti/transaksi)
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

                    // Supplier default untuk bukti ini (dibuat sekali, dipakai
                    // untuk semua barang di baris yang sama). Format bukti
                    // serah terima sering tidak punya kolom supplier sama
                    // sekali -- kalau kosong, jangan bikin Supplier ber-nama
                    // kosong, biarkan supplier_id null.
                    $namaSupplier = trim((string) ($row['supplier'] ?? ''));
                    $supplierId = null;

                    if ($namaSupplier !== '') {
                        $supplierId = Supplier::firstOrCreate(['nama' => $namaSupplier])->id;
                    }

                    // === Resolusi Pekerja penerima untuk baris bukti ini ===
                    // Sama seperti $infoBukti, ini SATU per baris (bukan per
                    // barang), lalu dipakai berulang untuk tiap Aset & baris
                    // AsetPemakai yang dibuat dari baris Excel yang sama.
                    //
                    // NIK di Excel disimpan ke kolom `nip` tabel pekerja
                    // (kolomnya belum diganti nama, sementara begini dulu).
                    // Kalau User utk NIK ini belum ada, User + Pekerja baru
                    // otomatis dibuat (role default 'karyawan'). Email di-
                    // generate placeholder karena format bukti ini tidak
                    // punya kolom email -- silakan diedit manual belakangan
                    // kalau memang perlu email asli.
                    $namaPenerima = trim((string) ($row['penerima'] ?? ''));
                    $nikPenerima = trim((string) ($row['nik'] ?? ''));
                    $pekerjaPenerima = null;

                    if ($namaPenerima !== '') {
                        if ($nikPenerima !== '') {
                            $pekerjaPenerima = Pekerja::where('nip', $nikPenerima)->first();

                            if (!$pekerjaPenerima) {
                                $userPenerima = User::create([
                                    'name'     => $namaPenerima,
                                    'email'    => 'nik' . $nikPenerima . '@placeholder.local',
                                    'password' => Str::random(32),
                                    'role'     => 'karyawan',
                                ]);

                                $pekerjaPenerima = Pekerja::create([
                                    'user_id'       => $userPenerima->id,
                                    'nip'           => $nikPenerima,
                                    'departemen_id' => $departemenId,
                                ]);
                            }
                        } else {
                            // Ada nama penerima tapi NIK kosong -- tidak ada
                            // kunci yang aman buat mencocokkan/membuat Pekerja.
                            // Aset tetap dibuat, tapi TIDAK ditandai "dipakai"
                            // & tidak ada baris aset_pemakai untuk baris ini.
                            $this->errors[] = 'Baris data ke-' . ($index + 1) . ': ada nama penerima ("' . $namaPenerima . '") tapi NIK kosong, aset dibuat tanpa data pemakai.';
                        }
                    }

                    $statusAset = $pekerjaPenerima ? 'dipakai' : 'tersedia';
                    // === akhir bagian resolusi Pekerja penerima ===

                    $adaBarangDiproses = false;

                    foreach ($nomorBarang as $n) {
                        $namaBarang = $row["nama_barang_{$n}"] ?? null;

                        if (empty($namaBarang)) {
                            continue;
                        }

                        $adaBarangDiproses = true;

                        $jenis = JenisAset::firstOrCreate(
                            ['nama' => trim($namaBarang)]
                        );

                        $keteranganAsli = $row["keterangan_{$n}"] ?? null;
                        $hasilParse = $this->parseKeterangan($keteranganAsli);

                        $aset = Aset::create(array_merge($infoBukti, [
                            'jenis_id'      => $jenis->id,
                            'supplier_id'   => $supplierId,
                            'jumlah'        => $row["jumlah_{$n}"] ?? null,
                            'keterangan'    => $keteranganAsli,
                            'serial_number' => $hasilParse['serial_number'],
                            'warna'         => $hasilParse['warna'],
                            'status'        => $statusAset,
                        ]));

                        foreach ($hasilParse['kelengkapan'] as $namaKelengkapan) {
                            $kelengkapanMaster = KelengkapanMaster::firstOrCreate(
                                ['nama' => $namaKelengkapan]
                            );

                            AsetKelengkapan::create([
                                'aset_id'               => $aset->id,
                                'kelengkapan_master_id' => $kelengkapanMaster->id,
                            ]);
                        }

                        // Kalau penerima berhasil diresolusi, catat sebagai
                        // pemakai aset ini. `status` di tabel aset_pemakai
                        // adalah status ALUR PERSETUJUAN (CHECK constraint
                        // cuma izinkan 'pending' | 'disetujui' | 'ditolak' --
                        // bukan status pemakaian fisik). Karena data ini dari
                        // bukti serah terima yang aset-nya SUDAH diserahkan
                        // (bukan permintaan yang masih menunggu approval),
                        // dipakai 'disetujui'. Kolom lain yang datanya tidak
                        // ada dari Excel (nomor_penerimaan, no_struk_penerimaan,
                        // diterima_at, catatan_penerimaan, dll) sengaja
                        // dibiarkan null.
                        if ($pekerjaPenerima) {
                            AsetPemakai::create([
                                'aset_id'            => $aset->id,
                                'pekerja_id'         => $pekerjaPenerima->id,
                                'user_id'            => $pekerjaPenerima->user_id,
                                'status'             => 'disetujui',
                                'tanggal_penerimaan' => $infoBukti['tanggal'],
                            ]);
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
     * Scan header, kumpulkan semua N dari kolom "nama_barang_N",
     * urutkan naik (1, 2, 3, 4, ...).
     */
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

    /**
     * Ekstrak serial_number, warna, dan daftar kelengkapan dari 1 teks
     * keterangan bebas. Lihat komentar PARSING KETERANGAN di atas class
     * untuk contoh & aturan lengkap.
     *
     * @return array{serial_number: ?string, warna: ?string, kelengkapan: string[]}
     */
    private function parseKeterangan(?string $teks): array
    {
        $hasil = ['serial_number' => null, 'warna' => null, 'kelengkapan' => []];

        if (empty(trim((string) $teks))) {
            return $hasil;
        }

        $sisaTeks = (string) $teks;

        // 1. Cari & keluarkan pola S/N atau SN (dengan/tanpa spasi & titik dua)
        if (preg_match('/\bS\s*\/?\s*N\s*:?\s*([A-Za-z0-9\-]+)/i', $sisaTeks, $match, PREG_OFFSET_CAPTURE)) {
            $hasil['serial_number'] = trim($match[1][0]);
            $panjangMatch = strlen($match[0][0]);
            $posisiMatch = $match[0][1];
            $sisaTeks = substr($sisaTeks, 0, $posisiMatch) . substr($sisaTeks, $posisiMatch + $panjangMatch);
        }

        // 2. Isi dalam kurung "(...)" selalu dianggap daftar kelengkapan
        if (preg_match_all('/\(([^)]*)\)/', $sisaTeks, $matchKurung)) {
            foreach ($matchKurung[1] as $isiKurung) {
                $hasil['kelengkapan'] = array_merge($hasil['kelengkapan'], $this->pisahDaftar($isiKurung));
            }
            $sisaTeks = preg_replace('/\(([^)]*)\)/', '', $sisaTeks);
        }

        // 3. Sisa teks dipisah per token; token pertama yang cocok kata
        //    kunci warna jadi `warna`, sisanya (kecuali Model/Imei/P/N)
        //    masuk `kelengkapan`
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

    /**
     * Pisah teks jadi daftar token berdasarkan koma/titik-koma, lalu tiap
     * token dipisah lagi kalau ada kata "dan" (mis. "manual book dan garansi").
     *
     * @return string[]
     */
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