<?php

namespace App\Notifications;

use App\Models\Transaksi\InventoryPenanganan;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class AsetKerusakanSelesai extends Notification
{
    use Queueable;

    public function __construct(protected InventoryPenanganan $penanganan)
    {
    }

    public function via(object $notifiable): array
    {
        // WebPushChannel dimatikan sementara: VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY
        // belum di-setup di Railway. Aktifkan lagi setelah VAPID key beres:
        // tambahkan WebPushChannel::class.
        return ['database', 'broadcast', WebPushChannel::class];
    }

    protected function namaAset(): string
    {
        $item = $this->penanganan->inventory;
        if (!$item) {
            return 'Aset';
        }

        return trim(($item->merek ?? 'Aset') . ' ' . ($item->tipe ?? ''));
    }

    protected function pesan(): string
    {
        return $this->penanganan->hasil === 'rusak_berat'
            ? "Laporan kerusakan {$this->penanganan->jenis_kerusakan} pada {$this->namaAset()} yang Anda laporkan sudah diperiksa. Sayangnya aset dinyatakan rusak berat dan tidak dapat diperbaiki."
            : "Laporan kerusakan {$this->penanganan->jenis_kerusakan} pada {$this->namaAset()} yang Anda laporkan sudah selesai diperbaiki dan bisa digunakan kembali.";
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'aset_kerusakan_selesai',
            'inventory_penanganan_id' => $this->penanganan->id,
            'inventory_id' => $this->penanganan->inventory_id,
            'jenis_kerusakan' => $this->penanganan->jenis_kerusakan,
            'hasil' => $this->penanganan->hasil,
            'message' => $this->pesan(),
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
            ->title($this->penanganan->hasil === 'rusak_berat' ? 'Aset Dinyatakan Rusak Berat' : 'Aset Sudah Bisa Digunakan')
            ->icon('/logo.png')
            ->body($this->pesan())
            ->data(['url' => '/penanganan-inventory'])
            ->options(['TTL' => 300]);
    }
}