<?php

namespace App\Notifications;

use App\Models\PengajuanIzin;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class IzinBaruDiajukan extends Notification
{
    use Queueable;

    public function __construct(protected PengajuanIzin $izin)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', WebPushChannel::class];
    }

    protected function namaKaryawan(): string
    {
        return $this->izin->karyawan->name ?? 'Karyawan';
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'izin_baru',
            'izin_id' => $this->izin->id,
            'nomor_izin' => $this->izin->nomor_izin,
            'message' => "{$this->namaKaryawan()} mengajukan izin baru ({$this->izin->nomor_izin}), menunggu persetujuan.",
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title('Pengajuan Izin Baru')
            ->icon('/logo.png')
            ->body("{$this->namaKaryawan()} mengajukan izin baru ({$this->izin->nomor_izin}), menunggu persetujuan.")
            ->data(['url' => '/izin'])
            ->options(['TTL' => 300]);
    }
}