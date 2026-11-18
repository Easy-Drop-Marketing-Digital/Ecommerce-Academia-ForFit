<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Cria (ou atualiza) o usuário dono do painel admin.
 *   php artisan admin:owner                  → usa ADMIN_EMAIL / ADMIN_PASSWORD do .env
 *   php artisan admin:owner email@x.com      → pergunta a senha (duas vezes)
 */
class CreateOwner extends Command
{
    protected $signature = 'admin:owner {email?} {--name=Dono}';

    protected $description = 'Cria ou atualiza o usuário dono do painel /admin';

    public function handle(): int
    {
        $email = $this->argument('email') ?: env('ADMIN_EMAIL');
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('E-mail inválido. Use: php artisan admin:owner seu@email.com');

            return self::FAILURE;
        }

        $password = $this->argument('email') ? $this->askPassword() : env('ADMIN_PASSWORD');
        if ($password === null && $this->argument('email')) {
            return self::FAILURE; // senhas não conferiram (mensagem já mostrada)
        }
        if (! $password || strlen($password) < 10) {
            $this->error('Senha precisa ter no mínimo 10 caracteres.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => mb_strtolower($email)], [
            'name' => $this->option('name'),
            'password' => $password,
            'role' => 'owner',
        ]);
        $this->info("Dono pronto: {$user->email} — acesse ".url('/admin'));

        return self::SUCCESS;
    }

    /**
     * Pede a senha escondida; em hospedagem que desliga funções do sistema (sem como esconder),
     * pergunta à vista com aviso. Sempre confirma digitando duas vezes.
     */
    private function askPassword(): ?string
    {
        $ask = function (string $label) {
            try {
                return $this->secret($label);
            } catch (\Throwable) {
                static $warned = false;
                if (! $warned) {
                    $this->warn('Este servidor não permite esconder o que é digitado: a senha vai aparecer na tela. Rode "clear" depois.');
                    $warned = true;
                }

                return $this->ask($label);
            }
        };

        $first = $ask('Senha (mínimo 10 caracteres)');
        $second = $ask('Repita a senha');
        if ($first !== $second) {
            $this->error('As senhas não conferem. Rode o comando de novo.');

            return null;
        }

        return $first;
    }
}
