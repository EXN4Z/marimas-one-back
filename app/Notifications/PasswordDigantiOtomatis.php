<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordDigantiOtomatis extends Notification
{
    use Queueable;

    public function __construct(protected string $passwordBaru)
    {
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Password Anda Telah Diperbarui')
            ->greeting('Halo, ' . $notifiable->name)
            ->line('Anda baru saja logout dari sistem. Untuk keamanan, password akun Anda telah diganti secara otomatis.')
            ->line('Password baru Anda:')
            ->line('**' . $this->passwordBaru . '**')
            ->line('Segera gunakan password ini untuk login berikutnya, dan jangan bagikan ke siapa pun.');
    }
}