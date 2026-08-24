<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Transaksi\InventoryPemakai;

/**
 * Data lama (sebelum fix rusak_berat/jual auto-nutup loan) bisa nyisain
 * record inventory_pemakai berstatus 'disetujui' + tanggal_pengembalian null
 * padahal inventory-nya sendiri udah bukan 'dipakai' lagi.
 */
#[Signature('app:fix-orphaned-aset-pemakai {--dry-run : Cuma tampilin yang bakal dibenerin, gak nyimpen perubahan}')]
#[Description('Tutup paksa record inventory_pemakai yang masih aktif padahal itemnya udah gak berstatus dipakai')]
class FixOrphanedAsetPemakai extends Command
{
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        $orphans = InventoryPemakai::query()
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->whereHas('inventory', fn ($q) => $q->where('status', '!=', 'dipakai'))
            ->with('inventory')
            ->get();

        if ($orphans->isEmpty()) {
            $this->info('Gak ada data nyangkut. Aman.');
            return;
        }

        foreach ($orphans as $p) {
            $this->line("- inventory_pemakai #{$p->id} · {$p->inventory?->kode_inventory} (status: {$p->inventory?->status})");
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
                    ?? 'Dikembalikan otomatis — perbaikan data lama (loan nyangkut, item sudah tidak berstatus dipakai).',
            ]);
        }

        $this->info(count($orphans) . ' record inventory_pemakai berhasil dibenerin.');
    }
}
