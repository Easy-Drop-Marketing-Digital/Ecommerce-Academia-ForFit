<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ShippingMethod extends Model
{
    use \App\Models\Concerns\FlushesPageCache;

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_pickup' => 'boolean',
    ];

    private const MONTHS = ['jan.', 'fev.', 'mar.', 'abr.', 'mai.', 'jun.', 'jul.', 'ago.', 'set.', 'out.', 'nov.', 'dez.'];

    /** Preço efetivo para um subtotal (frete grátis acima de X). */
    public function priceFor(int $subtotal): int
    {
        return $this->free_above !== null && $subtotal >= $this->free_above ? 0 : $this->price;
    }

    /**
     * Forma de entrega pré-selecionada no carrinho e no checkout: a entrega em casa mais barata para o subtotal
     * (grátis quando houver). Retirada só é escolhida se for a única opção ativa.
     */
    public static function defaultFor(Collection $methods, int $subtotal): ?self
    {
        $delivery = $methods->reject(fn (self $m) => $m->is_pickup);

        return ($delivery->isNotEmpty() ? $delivery : $methods)
            ->sortBy(fn (self $m) => [$m->priceFor($subtotal), $m->position])
            ->first();
    }

    /** Quanto falta no carrinho para alguma entrega em casa ficar grátis (null = já está grátis ou não existe regra). */
    public static function missingForFree(Collection $methods, int $subtotal): ?int
    {
        $delivery = $methods->reject(fn (self $m) => $m->is_pickup);
        if ($delivery->contains(fn (self $m) => $m->priceFor($subtotal) === 0)) {
            return null;
        }
        $limit = $delivery->whereNotNull('free_above')->min('free_above');

        return $limit ? max(0, (int) $limit - $subtotal) : null;
    }

    /**
     * Previsão de entrega em dias úteis, contando o despacho (Admin → Configurações → Prazos de entrega).
     * Ex.: "entre 25 de nov. e 2 de dez." — null para retirada ou modalidade sem prazo cadastrado.
     */
    public function estimate(?CarbonImmutable $from = null): ?array
    {
        if ($this->is_pickup || ($this->min_days === null && $this->max_days === null)) {
            return null;
        }
        preg_match('/\d+/', (string) setting('delivery.processing_days', '1'), $m);
        $processing = (int) ($m[0] ?? 1);
        $start = ($from ?? CarbonImmutable::now('America/Sao_Paulo'))->startOfDay();
        $min = self::addBusinessDays($start, $processing + (int) ($this->min_days ?? $this->max_days));
        $max = self::addBusinessDays($start, $processing + (int) ($this->max_days ?? $this->min_days));
        $fmt = fn (CarbonImmutable $d) => $d->day.' de '.self::MONTHS[$d->month - 1];

        return [
            'from' => $min->toDateString(),
            'to' => $max->toDateString(),
            'label' => $min->equalTo($max) ? 'receba até '.$fmt($max) : 'receba entre '.$fmt($min).' e '.$fmt($max),
        ];
    }

    private static function addBusinessDays(CarbonImmutable $date, int $days): CarbonImmutable
    {
        while ($days > 0) {
            $date = $date->addDay();
            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date;
    }
}
