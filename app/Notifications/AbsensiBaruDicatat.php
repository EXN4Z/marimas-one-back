<?php

namespace App\Notifications;

use App\Models\Absensi;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AbsensiBaruDicatat extends Notification
{
    use Queueable;

    public function __construct(protected Absensi $absensi)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $namaKaryawan = $this->absensi->pekerja->user->name ?? 'Karyawan';
        $statusLabel = $this->absensi->status === 'telat' ? 'telat' : 'tepat waktu';

        return [
            'type' => 'absensi_baru',
            'absensi_id' => $this->absensi->id,
            'status' => $this->absensi->status,
            'message' => "{$namaKaryawan} absen masuk ({$statusLabel}).",
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
