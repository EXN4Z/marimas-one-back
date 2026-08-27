<?php

namespace App\Notifications;

use App\Models\Transaksi\InventoryPenanganan;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class AsetKerusakanDilaporkan extends Notification
{
    use Queueable;

    public function __construct(protected InventoryPenanganan $penanganan)
    {
    }

    public function via(object $notifiable): array
    {
        // WebPushChannel dimatikan sementara: VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY
        // belum di-setup di Railway, jadi channel ini selalu throw dan bikin
        // notif database/broadcast ke penerima lain ikut gak terkirim.
        // Aktifkan lagi setelah VAPID key beres: tambahkan WebPushChannel::class.
        return ['database', 'broadcast', WebPushChannel::class];
    }

    protected function namaPelapor(): string
    {
        return $this->penanganan->pemakai?->user?->name ?? 'Karyawan';
    }

    protected function namaAset(): string
    {
        $item = $this->penanganan->inventory;
        if (!$item) {
            return 'Aset';
        }

        return $item->nama ?: 'Aset';
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'aset_kerusakan',
            'inventory_penanganan_id' => $this->penanganan->id,
            'inventory_id' => $this->penanganan->inventory_id,
            'jenis_kerusakan' => $this->penanganan->jenis_kerusakan,
            'message' => "{$this->namaPelapor()} melaporkan kerusakan {$this->penanganan->jenis_kerusakan} pada {$this->namaAset()}.",
            'url' => '/penanganan-aset',
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title('Laporan Kerusakan Aset')
            ->icon('/logo.png')
            ->body("{$this->namaPelapor()} melaporkan kerusakan {$this->penanganan->jenis_kerusakan} pada {$this->namaAset()}.")
            ->data(['url' => '/penanganan-aset'])
            ->options(['TTL' => 300]);
    }
}