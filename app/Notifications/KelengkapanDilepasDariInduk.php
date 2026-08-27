<?php

namespace App\Notifications;

use App\Models\MasterData\Inventory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class KelengkapanDilepasDariInduk extends Notification
{
    use Queueable;

    // $indukLabel ditangkap sebelum parent_id dikosongin di controller --
    // begitu lepas dari induk selesai, kelengkapan udah lepas dari induknya
    // jadi relasi parent gak bisa diandalkan lagi di sini.
    public function __construct(
        protected Inventory $kelengkapan,
        protected ?string $indukLabel,
        protected string $statusBaru,
        protected ?string $keterangan,
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
        return $this->kelengkapan->nama ?: $this->kelengkapan->kode_inventory;
    }

    protected function pesan(): string
    {
        $lokasi = $this->indukLabel ? " (sebelumnya terpasang di {$this->indukLabel})" : '';
        $keterangan = $this->keterangan ? " Keterangan: {$this->keterangan}" : '';

        return "{$this->pelaporName} melepas kelengkapan {$this->namaKelengkapan()}{$lokasi} dari induknya, status diubah jadi {$this->statusBaru}.{$keterangan}";
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'kelengkapan_dilepas_dari_induk',
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
            ->title('Kelengkapan Dilepas dari Induk')
            ->icon('/logo.png')
            ->body($this->pesan())
            ->data(['url' => '/master-data?tab=kelengkapan_aset'])
            ->options(['TTL' => 300]);
    }
}