<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'Pedidos';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Pedido')->formatStateUsing(fn ($state) => '#'.$state)->weight('bold'),
                TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i'),
                TextColumn::make('total')->label('Total')->formatStateUsing(fn ($state) => money($state)),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn ($state) => Order::STATUSES[$state] ?? $state)->color(fn ($state) => OrderResource::statusColor($state)),
            ])
            ->recordActions([
                Action::make('abrir')->label('Abrir')->url(fn (Order $r) => OrderResource::getUrl('view', ['record' => $r])),
            ]);
    }
}
