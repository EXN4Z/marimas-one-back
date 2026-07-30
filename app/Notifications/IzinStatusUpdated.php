<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\PengajuanIzin;
use Illuminate\Notifications\Messages\BroadcastMessage;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class IzinStatusUpdated extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(protected PengajuanIzin $izin)
    {

    }

    public function via(object $notifiable): array
    {
        // WebPushChannel dimatikan sementara: VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY
        // belum di-setup di Railway, jadi channel ini selalu throw.
        // Aktifkan lagi setelah VAPID key beres: tambahkan WebPushChannel::class.
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'izin_status',
            'izin_id' => $this->izin->id,
            'nomor_izin' => $this->izin->nomor_izin,
            'status' => $this->izin->status,
            'message' => "Pengajuan izin {$this->izin->nomor_izin} telah {$this->izin->status}.",
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
            ->title('Status Izin Diperbarui')
            ->icon('/logo.png')
            ->body("Pengajuan izin {$this->izin->nomor_izin} telah {$this->izin->status}.")
            ->data(['url' => '/izin'])
            ->options(['TTL' => 300]);
    }
}