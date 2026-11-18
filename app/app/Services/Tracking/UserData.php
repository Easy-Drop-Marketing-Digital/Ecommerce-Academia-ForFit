<?php

namespace App\Services\Tracking;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Dados da cliente para correspondência de eventos (Meta CAPI / TikTok Events API / Pixel avançado).
 *
 * Normalização segundo a documentação do Meta: minúsculas, sem espaços/acentos/pontuação onde indicado,
 * telefone com DDI 55, estado em sigla, país "br". Tudo que identifica a pessoa vai em SHA-256;
 * IP, user agent, fbp e fbc vão em texto (exigência das plataformas).
 */
class UserData
{
    /** @var array<string, string|null> valores normalizados (antes do hash) */
    private array $raw = [];

    public static function make(): self
    {
        return new self;
    }

    public static function fromOrder(Order $o): self
    {
        $a = $o->shipping_address ?? [];
        [$fn, $ln] = self::splitName($o->customer_name);

        return self::make()->set([
            'email' => $o->customer_email,
            'phone' => $o->customer_phone,
            'first_name' => $fn,
            'last_name' => $ln,
            'city' => $a['city'] ?? null,
            'state' => $a['state'] ?? null,
            'zip' => $a['zip'] ?? null,
            'external_id' => $o->visitor_id ?: ($o->customer_id ? 'c'.$o->customer_id : null),
            'ip' => $o->client_ip,
            'user_agent' => $o->user_agent,
            'fbp' => $o->fbp,
            'fbc' => $o->fbc,
            'ttclid' => $o->ttclid,
            'ttp' => $o->ttp,
        ]);
    }

    /** Monta a partir do que já se sabe da visitante: conta logada, dados digitados no checkout, cookies. */
    public static function fromContext(array $ctx, ?Cart $cart = null, ?Customer $customer = null): self
    {
        $d = $cart?->checkout_data ?? [];
        $name = $customer?->name ?? $cart?->name ?? ($d['name'] ?? null);
        [$fn, $ln] = self::splitName($name);

        return self::make()->set([
            'email' => $customer?->email ?? $cart?->email ?? ($d['email'] ?? null),
            'phone' => $customer?->phone ?? $cart?->phone ?? ($d['phone'] ?? null),
            'first_name' => $fn,
            'last_name' => $ln,
            'city' => $d['city'] ?? null,
            'state' => $d['state'] ?? null,
            'zip' => $d['zip'] ?? null,
            'external_id' => $ctx['visitor_id'] ?? null,
            'ip' => $ctx['ip'] ?? null,
            'user_agent' => $ctx['user_agent'] ?? null,
            'fbp' => $ctx['fbp'] ?? null,
            'fbc' => $ctx['fbc'] ?? null,
            'ttclid' => $ctx['ttclid'] ?? null,
            'ttp' => $ctx['ttp'] ?? null,
        ]);
    }

    public function set(array $values): self
    {
        foreach ($values as $k => $v) {
            $this->raw[$k] = $v === null || $v === '' ? null : self::normalize($k, (string) $v);
        }

        return $this;
    }

    public function get(string $key): ?string
    {
        return $this->raw[$key] ?? null;
    }

    public static function hash(?string $v): ?string
    {
        return $v === null || $v === '' ? null : hash('sha256', $v);
    }

    /** user_data da API de Conversões do Meta */
    public function forMeta(): array
    {
        $h = fn ($k) => self::hash($this->raw[$k] ?? null);

        return array_filter([
            'em' => ($v = $h('email')) ? [$v] : null,
            'ph' => ($v = $h('phone')) ? [$v] : null,
            'fn' => ($v = $h('first_name')) ? [$v] : null,
            'ln' => ($v = $h('last_name')) ? [$v] : null,
            'ct' => ($v = $h('city')) ? [$v] : null,
            'st' => ($v = $h('state')) ? [$v] : null,
            'zp' => ($v = $h('zip')) ? [$v] : null,
            'country' => [self::hash('br')],
            'external_id' => ($v = $h('external_id')) ? [$v] : null,
            'client_ip_address' => $this->raw['ip'] ?? null,
            'client_user_agent' => $this->raw['user_agent'] ?? null,
            'fbp' => $this->raw['fbp'] ?? null,
            'fbc' => $this->raw['fbc'] ?? null,
        ]);
    }

    /** Correspondência avançada do Pixel no navegador (fbq init) — já em hash */
    public function forPixel(): array
    {
        $h = fn ($k) => self::hash($this->raw[$k] ?? null);

        return array_filter([
            'em' => $h('email'), 'ph' => $h('phone'), 'fn' => $h('first_name'), 'ln' => $h('last_name'),
            'ct' => $h('city'), 'st' => $h('state'), 'zp' => $h('zip'), 'country' => self::hash('br'),
            'external_id' => $h('external_id'),
        ]);
    }

    /** "user" da TikTok Events API v1.3 */
    public function forTikTok(): array
    {
        return array_filter([
            'email' => self::hash($this->raw['email'] ?? null),
            'phone' => self::hash(isset($this->raw['phone']) ? '+'.$this->raw['phone'] : null), // E.164 antes do hash
            'external_id' => self::hash($this->raw['external_id'] ?? null),
            'ip' => $this->raw['ip'] ?? null,
            'user_agent' => $this->raw['user_agent'] ?? null,
            'ttclid' => $this->raw['ttclid'] ?? null,
            'ttp' => $this->raw['ttp'] ?? null,
        ]);
    }

    /** Quantos parâmetros de correspondência temos (diagnóstico de qualidade no admin) */
    public function score(): int
    {
        return count(array_filter(array_intersect_key($this->raw, array_flip(['email', 'phone', 'first_name', 'last_name', 'city', 'state', 'zip', 'external_id', 'ip', 'user_agent', 'fbp', 'fbc']))));
    }

    private static function normalize(string $key, string $v): ?string
    {
        $v = trim($v);

        return match ($key) {
            'email' => filter_var(mb_strtolower($v), FILTER_VALIDATE_EMAIL) ?: null,
            'phone' => self::phone($v),
            'first_name', 'last_name' => self::letters($v),
            'city' => str_replace(' ', '', self::letters($v) ?? '') ?: null,
            'state' => strlen($s = strtolower(preg_replace('/[^a-z]/i', '', $v))) === 2 ? $s : null,
            'zip' => strlen($z = preg_replace('/\D/', '', $v)) === 8 ? $z : null,
            'external_id' => mb_strtolower($v),
            default => $v,
        };
    }

    /** Somente dígitos com DDI 55 (ex.: 5511987654321) */
    private static function phone(string $v): ?string
    {
        $d = preg_replace('/\D/', '', $v);
        if (strlen($d) === 10 || strlen($d) === 11) {
            $d = '55'.$d;
        }

        return strlen($d) >= 12 ? $d : null;
    }

    /** Minúsculas, sem acentos e sem pontuação */
    private static function letters(string $v): ?string
    {
        $v = mb_strtolower(Str::ascii($v));
        $v = trim(preg_replace('/[^a-z ]/', '', $v));

        return $v !== '' ? $v : null;
    }

    private static function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        if (! $parts || $parts[0] === '') {
            return [null, null];
        }

        return [$parts[0], count($parts) > 1 ? end($parts) : null];
    }
}
