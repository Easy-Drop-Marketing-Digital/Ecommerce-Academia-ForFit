<?php

namespace App\Filament\Resources\EmailLogs\Pages;

use App\Filament\Resources\EmailLogs\EmailLogResource;
use App\Services\Mail\EmailPreview;
use App\Services\Mail\EmailService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageEmailLogs extends ManageRecords
{
    protected static string $resource = EmailLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Enviar e-mail de teste')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->modalHeading('Enviar e-mail de teste')
                ->modalDescription('Usa o pedido ou carrinho mais recente como exemplo e envia só para o endereço abaixo. Nada muda no pedido.')
                ->modalSubmitActionLabel('Enviar')
                ->schema([
                    Select::make('template')->label('Qual e-mail')->options(EmailService::TEMPLATES)->required()->searchable(),
                    TextInput::make('to')->label('Enviar para')->email()->required()->default(fn () => auth()->user()?->email),
                ])
                ->action(function (array $data) {
                    try {
                        $sample = app(EmailPreview::class)->data($data['template'], $data['to']);
                    } catch (\RuntimeException $e) {
                        Notification::make()->title('Sem exemplo para este e-mail')->body($e->getMessage())->warning()->send();

                        return;
                    }
                    $label = EmailService::TEMPLATES[$data['template']] ?? $data['template'];
                    $log = app(EmailService::class)->send($data['template'], $data['to'], '[TESTE] '.$label, $sample, null, force: true);

                    $log?->status === 'sent'
                        ? Notification::make()->title('E-mail de teste enviado para '.$data['to'])
                            ->body(config('mail.default') === 'log' ? 'A chave da Resend ainda não foi configurada: o e-mail foi gravado no log (storage/logs), não enviado de verdade.' : null)
                            ->success()->send()
                        : Notification::make()->title('Não foi possível enviar')->body($log?->error ?: 'Verifique o e-mail informado e a chave da Resend em Configurações → E-mail.')->danger()->send();
                }),
        ];
    }
}
