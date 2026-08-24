<?php

namespace App\Console\Commands;

use App\Models\MasterData\Inventory;
use Illuminate\Console\Command;

class BackfillTanggalPembelianAset extends Command
{
    protected $signature = 'aset:backfill-tanggal-pembelian';
    protected $description = 'Isi tanggal_pembelian dari kolom tanggal untuk inventory hasil Import Excel yang tanggal_pembelian-nya masih kosong';

    public function handle(): void
    {
        $count = Inventory::whereNull('tanggal_pembelian')
            ->whereNotNull('tanggal')
            ->update(['tanggal_pembelian' => \Illuminate\Support\Facades\DB::raw('tanggal')]);

        $this->info("{$count} inventory diperbarui: tanggal_pembelian diisi dari kolom tanggal.");
    }
}
