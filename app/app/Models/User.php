<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuário do painel admin.
 *  - owner: tudo, inclusive usuários e chaves de integração
 *  - admin: tudo exceto usuários
 *  - staff: operação (pedidos, clientes, produtos, estoque, avaliações) — sem configurações nem descontos
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Verificação em duas etapas (Google Authenticator, Authy, 1Password...) — segredo criptografado
    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    public const ROLES = ['owner' => 'Dono', 'admin' => 'Administrador', 'staff' => 'Equipe (operação)'];

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return array_key_exists($this->role, self::ROLES);
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /** Acesso a configurações, descontos, frete e integrações */
    public function canManageStore(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }
}
