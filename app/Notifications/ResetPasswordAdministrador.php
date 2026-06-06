<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordAdministrador extends BaseResetPassword
{
    public function toMail($notifiable)
    {

        $resetUrl = config('app.frontend_url')
            . '/admin/reset-password'
            . '?token=' . $this->token
            . '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Recuperar contraseña - Administración')
            ->view('emails.reset-password-admin', [
                'nombre'   => $notifiable->nombre,
                'email'    => $notifiable->email,
                'resetUrl' => $resetUrl,
            ]);
    }
}
