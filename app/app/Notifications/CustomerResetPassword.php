<?php

namespace App\Notifications;

use App\Mail\StoreMail;
use Illuminate\Auth\Notifications\ResetPassword;

/** E-mail de criar/redefinir senha da cliente, no layout da loja. */
class CustomerResetPassword extends ResetPassword
{
    protected function resetUrl($notifiable)
    {
        return route('account.password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
    }

    public function toMail($notifiable)
    {
        return (new StoreMail('password_reset', 'Criar nova senha — '.setting('store.name'), ['url' => $this->resetUrl($notifiable)]))
            ->to($notifiable->getEmailForPasswordReset());
    }
}
