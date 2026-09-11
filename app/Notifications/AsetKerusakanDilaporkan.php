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

    // $pelaporName ditangkap langsung dari user yang login & submit
    // laporan (request()->user()), BUKAN dari relasi pemakai -- soalnya
    // laporan kerusakan bisa juga dikirim pas item lagi nganggur/gak ada
    // pemakai aktif (audit gudang, atau admin lapor langsung), jadi
    // pemakai?->user?->name bisa null padahal pelapornya jelas ada.
    public function __construct(protected InventoryPenanganan $penanganan, protected string $pelaporName)
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
        return $this->pelaporName;
    }

    protected function namaAset(): string
    {
        $item = $this->penanganan->inventory;
        if (!$item) {
            return 'Aset';
        }

        return trim(($item->kode_inventory ?? '') . ' ' . ($item->nama ?? '')) ?: 'Aset';
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'aset_kerusakan',
            'inventory_penanganan_id' => $this->penanganan->id,
            'inventory_id' => $this->penanganan->inventory_id,
            'jenis_kerusakan' => $this->penanganan->jenis_kerusakan,
            'message' => "{$this->namaPelapor()} melaporkan kerusakan {$this->penanganan->jenis_kerusakan} pada {$this->namaAset()}.",
            'url' => '/penanganan-inventory',
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
            ->data(['url' => '/penanganan-inventory'])
            ->options(['TTL' => 300]);
    }
}