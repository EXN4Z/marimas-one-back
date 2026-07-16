<?php

namespace App\Notifications;

use App\Models\Barang;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class StokBarangRendah extends Notification
{
    use Queueable;

    public function __construct(protected Barang $barang)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'stok_rendah',
            'barang_id' => $this->barang->id,
            'stok' => $this->barang->stok,
            'stok_minimum' => $this->barang->stok_minimum,
            'message' => "Stok {$this->barang->nama} tinggal {$this->barang->stok} {$this->barang->satuan} (batas minimum {$this->barang->stok_minimum}).",
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}