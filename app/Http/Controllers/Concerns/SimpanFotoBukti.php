<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait SimpanFotoBukti
{
    /**
     * Disk tempat foto bukti (serah-terima, penerimaan kelengkapan, dsb)
     * disimpan. Dipisah jadi konstanta biar gampang diubah/di-swap tanpa
     * nyari-nyari string 'public' di banyak tempat.
     *
     * TETAP pakai disk 'public' (bukan S3) -- keputusan sadar, bukan lupa
     * diganti. Foldernya (storage/app/public, atau seluruh storage/)
     * di-mount ke Railway Volume supaya persisten antar-redeploy. Lihat
     * catatan lengkap di versi lama (AsetPemakaiController) soal syarat
     * volume + storage:link + kenapa harus tetap 1 replica.
     */
    private const DISK_FOTO_BUKTI = 'public';

    /**
     * Simpan array file upload ke disk (default: 'public', lihat
     * DISK_FOTO_BUKTI di atas) dan kembalikan array path-nya. Dipakai bareng
     * di beberapa controller (InventoryController, InventoryPemakaiController,
     * dst) buat menyimpan foto bukti dalam bentuk array (maksimal 3 foto),
     * yang disimpan sebagai JSON di kolom terkait (foto_penerimaan,
     * foto_pengembalian, dll).
     */
    protected function simpanFotoBukti(Request $request, string $field, string $folder): array
    {
        $disk = config('filesystems.disk_aset', self::DISK_FOTO_BUKTI);

        $paths = [];
        foreach ($request->file($field, []) as $file) {
            $paths[] = $file->store($folder, $disk);
        }

        return $paths;
    }
}