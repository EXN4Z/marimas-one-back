<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\PengajuanCuti;
use Carbon\Carbon;

#[Signature('app:delete-expired-cuti')]
#[Description('Command description')]
class DeleteExpiredCuti extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        PengajuanCuti::where('tanggal_selesai', '<', Carbon::now())->delete();


        $this->info('Expired cuti records deleted successfully.');
    }
}
