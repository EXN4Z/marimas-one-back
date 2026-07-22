<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TicketBaruMasuk extends Notification
{
    use Queueable;

    public function __construct(protected Ticket $ticket)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $namaPelapor = $this->ticket->pelapor->name ?? 'Karyawan';

        return [
            'type' => 'ticket_baru',
            'ticket_id' => $this->ticket->id,
            'judul' => $this->ticket->judul,
            'kategori' => $this->ticket->kategori,
            'message' => "{$namaPelapor} membuat laporan baru: {$this->ticket->judul}.",
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
