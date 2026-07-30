<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Aset;
use App\Models\AsetPemakai;

/**
 * Data lama (sebelum fix rusak_berat/jual auto-nutup loan) bisa nyisain
 * record aset_pemakai berstatus 'disetujui' + tanggal_pengembalian null
 * padahal asetnya sendiri udah bukan 'dipakai' lagi (misal tersedia, rusak,
 * rusak_berat, dijual). Command ini nutup paksa loan-loan nyangkut itu
 * SEKALI JALAN -- gak bakal ke-trigger lagi ke depannya karena alur normal
 * (kembalikan/rusak_berat/jual) sekarang udah otomatis nutup loan aktif.
 */
#[Signature('app:fix-orphaned-aset-pemakai {--dry-run : Cuma tampilin yang bakal dibenerin, gak nyimpen perubahan}')]
#[Description('Tutup paksa record aset_pemakai yang masih aktif padahal asetnya udah gak berstatus dipakai')]
class FixOrphanedAsetPemakai extends Command
{
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $orphans = AsetPemakai::query()
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->whereHas('aset', fn ($q) => $q->where('status', '!=', 'dipakai'))
            ->with('aset')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('Gak ada data nyangkut. Aman.');
            return;
        }

        foreach ($orphans as $p) {
            $this->line("- aset_pemakai #{$p->id} · aset {$p->aset?->kode_aset} (status aset: {$p->aset?->status})");
        }

        if ($dryRun) {
            $this->warn(count($orphans) . ' record bakal dibenerin (dry-run, gak disimpen).');
            return;
        }

        foreach ($orphans as $p) {
            $p->update([
                'tanggal_pengembalian' => $p->tanggal_penerimaan ?? now(),
                'dikembalikan_at' => now(),
                'catatan_pengembalian' => $p->catatan_pengembalian
                    ?? 'Dikembalikan otomatis — perbaikan data lama (loan nyangkut, aset sudah tidak berstatus dipakai).',
            ]);
        }

        $this->info(count($orphans) . ' record aset_pemakai berhasil dibenerin.');
    }
}