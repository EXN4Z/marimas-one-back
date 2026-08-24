<?php

namespace App\Notifications;

use App\Models\MasterData\Inventory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class AsetKelengkapanKerusakanDilaporkan extends Notification
{
    use Queueable;

    // $asetIndukLabel & $pelaporName ditangkap sebelum parent_id dikosongin
    // di controller — begitu lapor rusak selesai, kelengkapan udah lepas
    // dari induknya jadi relasi parent gak bisa diandalkan lagi di sini.
    public function __construct(
        protected Inventory $kelengkapan,
        protected ?string $asetIndukLabel,
        protected string $pelaporName
    ) {
    }

    public function via(object $notifiable): array
    {
        // WebPushChannel dimatikan sementara: VAPID_PUBLIC_KEY/VAPID_PRIVATE_KEY
        // belum di-setup di Railway. Aktifkan lagi setelah VAPID key beres:
        // tambahkan WebPushChannel::class.
        return ['database', 'broadcast', WebPushChannel::class];
    }

    protected function namaKelengkapan(): string
    {
        return $this->kelengkapan->nama
            ?: trim(($this->kelengkapan->merek ?? '') . ' ' . ($this->kelengkapan->tipe ?? ''))
            ?: $this->kelengkapan->kode_inventory;
    }

    protected function pesan(): string
    {
        $lokasi = $this->asetIndukLabel ? " (terpasang di {$this->asetIndukLabel})" : '';
        return "{$this->pelaporName} melaporkan kelengkapan {$this->namaKelengkapan()}{$lokasi} rusak.";
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'aset_kelengkapan_kerusakan',
            'inventory_id' => $this->kelengkapan->id,
            'message' => $this->pesan(),
            'url' => '/master-data?tab=kelengkapan_aset',
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function toWebPush($notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage())
            ->title('Laporan Kerusakan Kelengkapan')
            ->icon('/logo.png')
            ->body($this->pesan())
            ->data(['url' => '/master-data?tab=kelengkapan_aset'])
            ->options(['TTL' => 300]);
    }
}
