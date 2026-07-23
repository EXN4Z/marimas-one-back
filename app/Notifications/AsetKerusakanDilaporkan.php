<?php

namespace App\Notifications;

use App\Models\AsetPenanganan;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class AsetKerusakanDilaporkan extends Notification
{
    use Queueable;

    public function __construct(protected AsetPenanganan $penanganan)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', WebPushChannel::class];
    }

    protected function namaPelapor(): string
    {
        return $this->penanganan->pemakai?->pekerja?->user?->name
            ?? $this->penanganan->pemakai?->pekerja?->nama
            ?? 'Karyawan';
    }

    protected function namaAset(): string
    {
        $aset = $this->penanganan->aset;
        if (!$aset) {
            return 'Aset';
        }

        return trim(($aset->jenis?->nama ?? 'Aset') . ' ' . ($aset->merek ?? ''));
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'aset_kerusakan',
            'aset_penanganan_id' => $this->penanganan->id,
            'aset_id' => $this->penanganan->aset_id,
            'jenis_kerusakan' => $this->penanganan->jenis_kerusakan,
            'message' => "{$this->namaPelapor()} melaporkan kerusakan {$this->penanganan->jenis_kerusakan} pada {$this->namaAset()}.",
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
            ->data(['url' => '/inventaris?tab=penanganan'])
            ->options(['TTL' => 300]);
    }
}