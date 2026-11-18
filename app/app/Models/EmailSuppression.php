<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** E-mails que não recebem marketing (carrinho abandonado etc.). E-mails do pedido continuam sendo enviados. */
class EmailSuppression extends Model
{
    protected $fillable = ['email', 'reason'];

    public static function suppress(string $email, string $reason = 'unsubscribe'): void
    {
        $email = mb_strtolower(trim($email));
        static::firstOrCreate(['email' => $email], ['reason' => $reason]);
        Customer::where('email', $email)->update(['accepts_marketing' => false]);
        Cart::where('email', $email)->where('status', '!=', 'converted')->update(['recovery_emails_sent' => 3]);
    }

    public static function isSuppressed(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        return static::where('email', $email)->exists()
            || Customer::where('email', $email)->where('accepts_marketing', false)->exists();
    }
}
