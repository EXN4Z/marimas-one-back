<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inventory extends Model
{
    protected $table = 'inventory';

    protected $fillable = [
        'parent_id',
        'master_kategori_id',
        'departemen_id',
        'lokasi_kantor_id',
        'nama',
        'merek',
        'tipe',
        'warna',
        'serial_number',
        'jumlah',
        'no_bukti',
        'tanggal',
        'nik',
        'penerima',
        'tanggal_garansi',
        'perusahaan',
        'keterangan',
        'diterima_oleh',
        'diketahui',
        'dibuat_oleh',
        'diketahui_hrd',
        'foto',
        'supplier_id',
        'tanggal_pembelian',
        'no_surat_jalan',
        'no_good_receive',
        'status',
        'tanggal_rusak',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'tanggal_garansi' => 'date',
        'tanggal_pembelian' => 'date',
        'tanggal_rusak' => 'datetime',
    ];

    // ================= Relasi kategori =================

    public function masterKategori()
    {
        return $this->belongsTo(MasterKategori::class, 'master_kategori_id');
    }

    /**
     * Shortcut buat baca kode kategori (barang_utama / kelengkapan) tanpa
     * harus manggil relasi dua tingkat di banyak tempat. Kalau
     * masterKategori/kategori-nya belum di-eager-load, ini bakal lazy-load
     * (2 query) -- di controller yang butuh dipanggil berkali-kali (mis.
     * dalam loop), pastikan eager-load 'masterKategori.kategori' dulu.
     */
    public function kategoriKode(): ?string
    {
        return $this->masterKategori?->kategori?->kode;
    }

    public function isBarangUtama(): bool
    {
        return $this->kategoriKode() === 'barang_utama';
    }

    public function isKelengkapan(): bool
    {
        return $this->kategoriKode() === 'kelengkapan';
    }

    public function scopeBarangUtama($query)
    {
        return $query->whereHas(
            'masterKategori.kategori',
            fn ($q) => $q->where('kode', 'barang_utama')
        );
    }

    public function scopeKelengkapan($query)
    {
        return $query->whereHas(
            'masterKategori.kategori',
            fn ($q) => $q->where('kode', 'kelengkapan')
        );
    }

    // ================= Self-relation (parent/anak) =================

    // barang utama tempat kelengkapan ini menempel (null = berdiri sendiri
    // ATAU baris ini sendiri adalah barang utama)
    public function parent()
    {
        return $this->belongsTo(Inventory::class, 'parent_id');
    }

    // kelengkapan yang menempel ke baris ini (cuma relevan kalau baris ini
    // barang utama -- kelengkapan gak boleh punya anak, ditegakkan di
    // controller)
    public function children()
    {
        return $this->hasMany(Inventory::class, 'parent_id');
    }

    // ================= Relasi lain =================

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function departemen()
    {
        return $this->belongsTo(Departemen::class, 'departemen_id');
    }

    // lokasi fisik item ini kalau BERDIRI SENDIRI (kelengkapan tanpa
    // parent) -- eks kolom aset_kelengkapan.lokasi_kantor_id
    public function lokasiKantor()
    {
        return $this->belongsTo(LokasiKantor::class, 'lokasi_kantor_id');
    }

    public function pemakai()
    {
        return $this->hasMany(InventoryPemakai::class, 'inventory_id')->latest('tanggal_penerimaan');
    }

    public function pemakaiSaatIni()
    {
        return $this->hasOne(InventoryPemakai::class, 'inventory_id')
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->latest('tanggal_penerimaan');
    }

    public function pemakaiPending()
    {
        return $this->hasMany(InventoryPemakai::class, 'inventory_id')
            ->where('status', 'pending')
            ->latest();
    }

    // riwayat lengkap perbaikan/penanganan kerusakan (semua status, terbaru
    // dulu) -- cuma relevan buat barang utama, kelengkapan gak lewat alur
    // ini (lihat InventoryController::laporRusakKelengkapan)
    public function penanganan()
    {
        return $this->hasMany(InventoryPenanganan::class, 'inventory_id')->latest('tanggal_lapor');
    }

    // laporan kerusakan yang belum ditangani (tanggal_selesai masih null)
    public function penangananAktif()
    {
        return $this->hasOne(InventoryPenanganan::class, 'inventory_id')
            ->whereNull('tanggal_selesai')
            ->latest('tanggal_lapor');
    }

    // catatan penjualan/writeoff (kalau statusnya udah 'dijual')
    public function writeoff()
    {
        return $this->hasOne(InventoryWriteoff::class, 'inventory_id');
    }
}