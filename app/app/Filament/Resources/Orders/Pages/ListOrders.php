<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $count = fn (array $statuses) => Order::whereIn('status', $statuses)->count();

        return [
            'todos' => Tab::make('Todos'),
            'enviar' => Tab::make('Pagos · a enviar')->badge($count(['paid', 'processing']))->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['paid', 'processing'])),
            'aguardando' => Tab::make('Aguardando pagamento')->badge($count(['pending']))->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'enviados' => Tab::make('Enviados')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'shipped')),
            'entregues' => Tab::make('Entregues')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'delivered')),
            'cancelados' => Tab::make('Cancelados / reembolsados')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['canceled', 'refunded'])),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'todos';
    }
}
