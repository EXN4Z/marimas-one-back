<?php

namespace App\Notifications;

use App\Models\PengajuanIzin;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class IzinBaruDiajukan extends Notification
{
    use Queueable;

    public function __construct(protected PengajuanIzin $izin)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $namaKaryawan = $this->izin->karyawan->name ?? 'Karyawan';

        return [
            'type' => 'izin_baru',
            'izin_id' => $this->izin->id,
            'nomor_izin' => $this->izin->nomor_izin,
            'message' => "{$namaKaryawan} mengajukan izin baru ({$this->izin->nomor_izin}), menunggu persetujuan.",
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
