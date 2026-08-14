<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordAkunBaru extends Notification implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $passwordPlain
    ) {}

    // Kirim lewat email dulu. Kalau nanti ada gateway WA/SMS,
    // tinggal tambah channel custom di sini, misal 'whatsapp'.
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Akun Sistem Aset Kamu Sudah Dibuat')
            ->greeting('Halo, ' . $notifiable->name)
            ->line('Akun kamu sudah dibuat oleh admin.')
            ->line('Email login: ' . $notifiable->email)
            ->line('Password sementara: ' . $this->passwordPlain)
            ->line('Segera login dan ganti password kamu.');
    }
}