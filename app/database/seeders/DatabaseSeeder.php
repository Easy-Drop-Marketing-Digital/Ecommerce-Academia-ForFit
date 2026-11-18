<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Conteúdo inicial (páginas, políticas, menus do rodapé e frete) para uma loja sem pacote de catálogo.
 * Em produção o conteúdo vem do pacote (php artisan catalog:export / store:install --catalogo=...).
 * Não crie usuários aqui: o dono do admin é criado com `php artisan admin:owner email@dominio`
 * (senha pedida no terminal). Um usuário de teste com senha conhecida viraria porta aberta em produção.
 *
 * Atenção: rodar de novo sobrescreve páginas/menus editados no admin.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            StoreContentSeeder::class, // políticas, páginas, menus do rodapé, frete
        ]);
    }
}
