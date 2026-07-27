<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TicketStatusUpdated extends Notification
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
        $pelapor = $this->ticket->pelapor->name ?? 'Karyawan';

        return [
            'type' => 'ticket_status',
            'ticket_id' => $this->ticket->id,
            'judul' => $this->ticket->judul,
            'status' => $this->ticket->status,
            'message' => "Laporan \"{$this->ticket->judul}\" statusnya diubah jadi {$this->ticket->status}.",
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}