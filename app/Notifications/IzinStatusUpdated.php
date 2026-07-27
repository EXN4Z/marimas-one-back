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
        return ['database', 'broadcast', WebPushChannel::class];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'izin_status',
            'izin_id' => $this->izin->id,
            'nomor_izin' => $this->izin->nomor_izin,
            'status' => $this->izin->status,
            'message' => "Pengajuan izin {$this->izin->nomor_izin} telah {$this->izin->status}.",
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