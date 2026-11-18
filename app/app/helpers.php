<?php

use App\Support\StoreSettings;
use Illuminate\Support\Str;

if (! function_exists('money')) {
    /** 12990 → "R$ 129,90" */
    function money(?int $cents): string
    {
        return 'R$ '.number_format(($cents ?? 0) / 100, 2, ',', '.');
    }
}

if (! function_exists('discount_percent')) {
    function discount_percent(?int $price, ?int $compare): ?int
    {
        if (! $compare || ! $price || $compare <= $price) {
            return null;
        }

        return (int) round(100 - ($price * 100 / $compare));
    }
}

if (! function_exists('installment_plan')) {
    /**
     * Tabela de parcelamento conforme Admin → Configurações → Nixfy.
     * Parcelas acima de "sem juros" usam juros compostos (Tabela Price) à taxa mensal configurada.
     *
     * @return array<int, array{n:int, value:int, total:int, interest:bool}>
     */
    function installment_plan(int $cents): array
    {
        $max = max(1, (int) StoreSettings::get('nixfy.max_installments', 12));
        $free = max(1, (int) StoreSettings::get('nixfy.interest_free_installments', 1));
        $rate = (float) str_replace(',', '.', (string) StoreSettings::get('nixfy.installment_rate', '2.9')) / 100;
        $min = (int) round((float) str_replace(',', '.', (string) StoreSettings::get('nixfy.min_installment_value', '5')) * 100);

        $plan = [];
        for ($n = 1; $n <= $max; $n++) {
            if ($n <= $free || $rate <= 0) {
                $value = (int) floor($cents / $n);
                $interest = false;
            } else {
                $value = (int) floor($cents * $rate / (1 - (1 + $rate) ** -$n));
                $interest = true;
            }
            if ($n > 1 && $value < $min) {
                break;
            }
            $plan[$n] = ['n' => $n, 'value' => $value, 'total' => $interest ? $value * $n : $cents, 'interest' => $interest];
        }

        return $plan;
    }
}

if (! function_exists('max_installment')) {
    /** Maior parcela disponível — usada no "em até 12x de R$ 12,97" dos cards. */
    function max_installment(int $cents): array
    {
        $plan = installment_plan($cents);

        return end($plan) ?: ['n' => 1, 'value' => $cents, 'total' => $cents, 'interest' => false];
    }
}

if (! function_exists('pix_percent')) {
    /** Desconto no PIX configurado em Admin → Configurações → Nixfy (0 = sem desconto). */
    function pix_percent(): float
    {
        return max(0, (float) str_replace(',', '.', (string) StoreSettings::get('nixfy.pix_discount_percent', 0)));
    }
}

if (! function_exists('pix_price')) {
    /** Preço à vista no PIX (cards e página do produto). null quando não há desconto no PIX. */
    function pix_price(int $cents): ?int
    {
        $p = pix_percent();

        return $p > 0 ? (int) round($cents * (100 - $p) / 100) : null;
    }
}

if (! function_exists('img_url')) {
    /**
     * URL de imagem com largura pedida. CDN da Shopify aceita ?width=; arquivos locais são servidos como estão
     * (a otimização local entra junto com o download das imagens antes do go-live).
     */
    function img_url(?string $path, ?int $width = null): string
    {
        if (! $path) {
            return asset('images/placeholder.svg');
        }
        if (Str::startsWith($path, ['http://', 'https://', '//'])) {
            $url = Str::startsWith($path, '//') ? 'https:'.$path : $path;
            if ($width && str_contains($url, 'cdn.shopify.com') || ($width && str_contains($url, '/cdn/shop/'))) {
                $url .= (str_contains($url, '?') ? '&' : '?').'width='.$width;
            }

            return $url;
        }

        // fotos de produto locais têm vários tamanhos (…-r400.webp, …-r1200.webp): usa o mais próximo
        return asset('storage/'.ltrim(\App\Support\ImageOptimizer::sized($path, $width), '/'));
    }
}

if (! function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return StoreSettings::get($key, $default);
    }
}

if (! function_exists('lazy_media')) {
    /**
     * Imagens e vídeos de descrições (HTML livre do admin) só carregam quando a cliente rola até eles:
     * descrições com GIFs/vídeos pesados não atrasam a abertura da página.
     */
    function lazy_media(?string $html): string
    {
        $html = (string) $html;
        $html = preg_replace('#<img\b(?![^>]*\bloading=)#i', '<img loading="lazy" decoding="async"', $html) ?? $html;

        return preg_replace('#<video\b(?![^>]*\bpreload=)#i', '<video preload="none"', $html) ?? $html;
    }
}
