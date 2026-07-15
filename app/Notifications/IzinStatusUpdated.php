<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\PengajuanIzin;
use Illuminate\Notifications\Messages\BroadcastMessage;

class IzinStatusUpdated extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(protected PengajuanIzin $izin)
    {
       
    }

    public function toDatabase($notifiable) : array
    {
        return [
            'nomor_izin' => $this->izin->nomor_izin,
            'status' => $this->izin->status,
            'message' => "Pengajuan izin {$this->izin->nomor_izin} telah {$this->izin->status}.",
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
         return ['database', 'broadcast'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
