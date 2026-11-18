<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderManager;
use App\Services\Payments\NixfyGateway;
use App\Services\Payments\PaymentStatusUpdater;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Pedido #'.$this->record->number;
    }

    protected function getHeaderActions(): array
    {
        /** @var Order $o */
        $o = $this->record;
        $manager = app(OrderManager::class);
        $paidOpen = $o->isPaid() && ! in_array($o->status, ['canceled', 'refunded'], true);

        return [
            Action::make('ship')->label($o->status === 'shipped' ? 'Atualizar rastreio' : 'Marcar como enviado')
                ->icon(Heroicon::OutlinedTruck)->color('primary')->visible($paidOpen && $o->status !== 'delivered')
                ->schema([
                    TextInput::make('tracking_code')->label('Código de rastreio')->required()->default($o->tracking_code),
                    Select::make('carrier')->label('Transportadora')->default($o->carrier)
                        ->options(['Correios' => 'Correios', 'Jadlog' => 'Jadlog', 'Loggi' => 'Loggi', 'J&T Express' => 'J&T Express', 'Internacional' => 'Internacional (17Track)', 'Outra' => 'Outra']),
                    TextInput::make('tracking_url')->label('Link de rastreio (opcional)')->url()->default($o->tracking_url)
                        ->helperText('Em branco: Correios para códigos no padrão AA123456789BR, 17Track para os demais.'),
                ])
                ->action(function (array $data) use ($o, $manager) {
                    $manager->markShipped($o, $data['tracking_code'], $data['carrier'] ?? null, $data['tracking_url'] ?? null, auth()->id());
                    Notification::make()->title('Pedido marcado como enviado')->success()->send();
                    $this->refreshFormData(['status', 'tracking_code']);
                }),

            Action::make('delivered')->label('Marcar como entregue')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible($paidOpen && $o->status === 'shipped')->requiresConfirmation()
                ->action(function () use ($o, $manager) {
                    $manager->markDelivered($o, auth()->id());
                    Notification::make()->title('Pedido entregue')->success()->send();
                }),

            ActionGroup::make([
                Action::make('processing')->label('Marcar em separação')->icon(Heroicon::OutlinedArchiveBox)
                    ->visible($paidOpen && $o->status === 'paid')
                    ->action(fn () => $manager->markProcessing($o, auth()->id())),

                Action::make('note')->label('Adicionar nota interna')->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([Textarea::make('note')->label('Nota')->required()->rows(3)])
                    ->action(fn (array $data) => $manager->addNote($o, $data['note'], auth()->id())),

                Action::make('recheck')->label('Consultar pagamento na Nixfy')->icon(Heroicon::OutlinedArrowPath)
                    ->visible((bool) $o->gateway_uuid)
                    ->action(function () use ($o) {
                        $tx = app(NixfyGateway::class)->getTransaction($o->gateway_uuid);
                        app(PaymentStatusUpdater::class)->apply($o, $tx['status'] ?? 'PENDENTE', 'admin', ['fee' => $tx['systemFee'] ?? null]);
                        Notification::make()->title('Status na Nixfy: '.($tx['status'] ?? '?'))->success()->send();
                    }),

                Action::make('openPublic')->label('Ver página do pedido (cliente)')->icon(Heroicon::OutlinedEye)
                    ->url($o->publicUrl(), true),

                Action::make('cancel')->label($o->isPaid() ? 'Cancelar e reembolsar' : 'Cancelar pedido')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(! in_array($o->status, ['canceled', 'refunded', 'delivered'], true))
                    ->modalDescription($o->isPaid() ? 'O pedido já foi pago: depois de cancelar aqui, faça o estorno no painel da Nixfy.' : 'O pedido ainda não foi pago. Nenhum valor será cobrado.')
                    ->schema([
                        Textarea::make('reason')->label('Motivo')->required()->rows(2),
                        Toggle::make('restock')->label('Devolver itens ao estoque')->default(true),
                    ])
                    ->action(function (array $data) use ($o, $manager) {
                        $manager->cancel($o, $data['reason'], (bool) $data['restock'], auth()->id());
                        Notification::make()->title('Pedido cancelado')->success()->send();
                    }),
            ])->label('Mais ações')->button()->color('gray'),
        ];
    }
}
