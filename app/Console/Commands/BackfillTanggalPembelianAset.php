<?php

namespace App\Console\Commands;

use App\Models\Aset;
use Illuminate\Console\Command;

class BackfillTanggalPembelianAset extends Command
{
    protected $signature = 'aset:backfill-tanggal-pembelian';
    protected $description = 'Isi tanggal_pembelian dari kolom tanggal untuk aset hasil Import Excel yang tanggal_pembelian-nya masih kosong (bug lama di AsetBuktiImport/AsetBuktiRapiImport)';

    public function handle(): void
    {
        $count = Aset::whereNull('tanggal_pembelian')
            ->whereNotNull('tanggal')
            ->update(['tanggal_pembelian' => \Illuminate\Support\Facades\DB::raw('tanggal')]);

        $this->info("{$count} aset diperbarui: tanggal_pembelian diisi dari kolom tanggal.");
    }
}