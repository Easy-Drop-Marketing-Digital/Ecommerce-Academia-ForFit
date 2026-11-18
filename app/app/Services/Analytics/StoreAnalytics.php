<?php

namespace App\Services\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Métricas da loja para o painel (pedidos + analytics próprio). Cache de 2 minutos por período,
 * para o painel continuar leve mesmo com a loja cheia na Black Friday.
 */
class StoreAnalytics
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(?string $period = '7d', ?string $start = null, ?string $end = null)
    {
        $now = CarbonImmutable::now();
        [$this->from, $this->to] = match ($period) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfDay()],
            'custom' => [CarbonImmutable::parse($start ?: $now->subDays(6))->startOfDay(), CarbonImmutable::parse($end ?: $now)->endOfDay()],
            default => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
        };
    }

    public static function fromFilters(?array $f): self
    {
        return new self($f['period'] ?? '7d', $f['start'] ?? null, $f['end'] ?? null);
    }

    private function remember(string $key, \Closure $fn): mixed
    {
        return Cache::remember('analytics.'.$key.'.'.$this->from->timestamp.'.'.$this->to->timestamp, 120, $fn);
    }

    /** Pedidos pagos no período (exclui cancelados/reembolsados depois) */
    private function paid()
    {
        return DB::table('orders')->whereBetween('paid_at', [$this->from, $this->to])->whereNotIn('status', ['canceled', 'refunded']);
    }

    public function summary(): array
    {
        return $this->remember('summary', function () {
            $paid = $this->paid();
            $revenue = (int) (clone $paid)->sum('total');
            $paidCount = (int) (clone $paid)->count();
            $created = DB::table('orders')->whereBetween('created_at', [$this->from, $this->to]);
            $pixCreated = (int) (clone $created)->where('payment_method', 'pix')->whereNotNull('gateway_uuid')->count();
            $pixPaid = (int) (clone $created)->where('payment_method', 'pix')->whereNotNull('paid_at')->count();
            $cardTries = DB::table('card_attempts')->whereBetween('created_at', [$this->from, $this->to]);
            $cardTotal = (int) (clone $cardTries)->count();
            $cardRefused = (int) (clone $cardTries)->whereIn('status', ['refused', 'blocked'])->count();
            $sessions = (int) DB::table('visitor_sessions')->whereBetween('started_at', [$this->from, $this->to])->count();
            $costRows = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereBetween('orders.paid_at', [$this->from, $this->to])->whereNotIn('orders.status', ['canceled', 'refunded'])
                ->whereNotNull('order_items.unit_cost')
                ->selectRaw('SUM(order_items.total) as sold, SUM(order_items.unit_cost * order_items.quantity) as cost')->first();

            return [
                'revenue' => $revenue,
                'paid_orders' => $paidCount,
                'aov' => $paidCount ? intdiv($revenue, $paidCount) : 0,
                'orders_created' => (int) (clone $created)->count(),
                'sessions' => $sessions,
                'conversion' => $sessions ? round($paidCount * 100 / $sessions, 2) : null,
                'pix_created' => $pixCreated,
                'pix_paid' => $pixPaid,
                'pix_rate' => $pixCreated ? round($pixPaid * 100 / $pixCreated, 1) : null,
                'card_attempts' => $cardTotal,
                'card_refused_rate' => $cardTotal ? round($cardRefused * 100 / $cardTotal, 1) : null,
                'pix_share' => $paidCount ? round((clone $paid)->where('payment_method', 'pix')->count() * 100 / $paidCount, 1) : null,
                'margin' => $costRows && $costRows->cost ? (int) $costRows->sold - (int) $costRows->cost : null,
                'abandoned' => (int) DB::table('carts')->where('status', '!=', 'converted')
                    ->whereBetween('checkout_started_at', [$this->from, $this->to])->whereNotNull('email')->count(),
            ];
        });
    }

    /** Receita e pedidos pagos por dia (para o gráfico) */
    public function daily(): array
    {
        return $this->remember('daily', function () {
            $rows = $this->paid()->selectRaw('DATE(paid_at) as d, SUM(total) as revenue, COUNT(*) as n')->groupBy('d')->get()->keyBy('d');
            $out = [];
            foreach (CarbonPeriod::create($this->from->startOfDay(), $this->to->startOfDay()) as $day) {
                $k = $day->format('Y-m-d');
                $out[] = ['label' => $day->format('d/m'), 'revenue' => (int) ($rows[$k]->revenue ?? 0), 'orders' => (int) ($rows[$k]->n ?? 0)];
            }

            return $out;
        });
    }

    /** Funil por sessão: visitou → viu produto → carrinho → checkout → pedido → pago */
    public function funnel(): array
    {
        return $this->remember('funnel', function () {
            $sessionsWith = fn (string $event) => (int) DB::table('analytics_events')
                ->whereBetween('created_at', [$this->from, $this->to])->where('name', $event)
                ->distinct()->count('visitor_session_id');
            $steps = [
                ['label' => 'Sessões', 'value' => (int) DB::table('visitor_sessions')->whereBetween('started_at', [$this->from, $this->to])->count()],
                ['label' => 'Viram um produto', 'value' => $sessionsWith('view_item')],
                ['label' => 'Adicionaram ao carrinho', 'value' => $sessionsWith('add_to_cart')],
                ['label' => 'Iniciaram o checkout', 'value' => $sessionsWith('begin_checkout')],
                ['label' => 'Fizeram o pedido', 'value' => (int) DB::table('orders')->whereBetween('created_at', [$this->from, $this->to])->count()],
                ['label' => 'Pagaram', 'value' => (int) $this->paid()->count()],
            ];
            $top = max(1, $steps[0]['value']);
            foreach ($steps as $i => &$s) {
                $s['pct_total'] = round($s['value'] * 100 / $top, 1);
                $s['pct_prev'] = $i ? ($steps[$i - 1]['value'] ? round($s['value'] * 100 / $steps[$i - 1]['value'], 1) : 0) : 100;
            }

            return $steps;
        });
    }

    public function topProducts(int $limit = 10): array
    {
        return $this->remember('top'.$limit, fn () => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereBetween('orders.paid_at', [$this->from, $this->to])->whereNotIn('orders.status', ['canceled', 'refunded'])
            ->selectRaw('order_items.product_id, MAX(order_items.title) as title, SUM(order_items.quantity) as qty, SUM(order_items.total) as revenue')
            ->groupBy('order_items.product_id')->orderByDesc('revenue')->limit($limit)->get()
            ->map(fn ($r) => (array) $r)->all());
    }

    /** Vendas por origem/campanha (UTM gravada no pedido) */
    public function campaigns(int $limit = 12): array
    {
        return $this->remember('camp'.$limit, fn () => $this->paid()
            ->selectRaw("COALESCE(NULLIF(utm_source, ''), 'direto/orgânico') as source, COALESCE(utm_campaign, '—') as campaign, COUNT(*) as orders, SUM(total) as revenue")
            ->groupBy('source', 'campaign')->orderByDesc('revenue')->limit($limit)->get()
            ->map(fn ($r) => (array) $r)->all());
    }

    /** Tráfego por origem (sessões) com conversão */
    public function sources(): array
    {
        return $this->remember('sources', function () {
            $sessions = DB::table('visitor_sessions')->whereBetween('started_at', [$this->from, $this->to])
                ->selectRaw('COALESCE(source, "direto") as source, COUNT(*) as sessions')->groupBy('source')->pluck('sessions', 'source');
            $devices = DB::table('visitor_sessions')->whereBetween('started_at', [$this->from, $this->to])
                ->selectRaw('device, COUNT(*) as n')->groupBy('device')->pluck('n', 'device');

            return ['sessions' => $sessions->sortDesc()->all(), 'devices' => $devices->all()];
        });
    }
}
