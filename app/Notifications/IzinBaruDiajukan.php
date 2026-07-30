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
        // WebPushChannel dimatikan sementara: VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY
        // belum di-setup di Railway, jadi channel ini selalu throw dan bikin
        // notif database/broadcast ke penerima lain ikut gak terkirim.
        // Aktifkan lagi setelah VAPID key beres: tambahkan WebPushChannel::class.
        return ['database', 'broadcast'];
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
            'url' => '/izin',
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