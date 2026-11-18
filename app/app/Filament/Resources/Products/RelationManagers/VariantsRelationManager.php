<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\Money;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variações, preço e estoque';

    public function form(Schema $schema): Schema
    {
        $product = $this->getOwnerRecord();
        $options = $product->options()->orderBy('position')->get();
        $fields = [];
        foreach ($options->values() as $i => $opt) {
            $fields[] = Select::make('option'.($i + 1))->label($opt->name)->options(array_combine($opt->values, $opt->values))->required();
        }

        return $schema->columns(2)->components([
            ...$fields,
            Money::input('price', 'Preço de venda')->required(),
            Money::input('compare_at_price', 'Preço "de" (riscado)'),
            Money::input('cost', 'Custo no fornecedor')->helperText('Usado no relatório de margem.'),
            TextInput::make('stock')->label('Estoque')->numeric()->required()->default(1000),
            TextInput::make('sku')->label('SKU da loja')->helperText('Aparece na página e no catálogo do Meta/Google.'),
            TextInput::make('supplier_sku')->label('Código no fornecedor')->helperText('Uso interno: só na planilha do fornecedor.'),
            Select::make('image_id')->label('Foto da variação')->options($product->images()->orderBy('position')->pluck('position', 'id')->map(fn ($p) => "Foto $p"))->placeholder('Capa do produto'),
            TextInput::make('weight_grams')->label('Peso (g)')->numeric()->default(0),
            Toggle::make('is_active')->label('Ativa')->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        $money = fn (string $field) => TextInputColumn::make($field)
            ->getStateUsing(fn (ProductVariant $r) => $r->{$field} !== null ? number_format($r->{$field} / 100, 2, ',', '.') : null)
            ->updateStateUsing(function (ProductVariant $record, $state) use ($field) {
                $record->update([$field => Money::toCents($state)]);
            })
            ->rules(['nullable', 'regex:/^[\d.,]+$/'])
            ->extraInputAttributes(['inputmode' => 'decimal']);

        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->paginated([25, 50, 100])
            ->description('Preço, preço "de", estoque e SKU podem ser editados direto na tabela.')
            ->columns([
                TextColumn::make('title')->label('Variação')->weight('medium')->searchable(),
                $money('price')->label('Preço (R$)')->width('130px'),
                $money('compare_at_price')->label('Preço "de" (R$)')->width('130px'),
                TextInputColumn::make('stock')->label('Estoque')->type('number')->rules(['integer'])->width('100px')
                    ->updateStateUsing(function (ProductVariant $record, $state) {
                        $record->adjustStock((int) $state - $record->stock, 'manual', null, auth()->id());
                    }),
                TextInputColumn::make('sku')->label('SKU')->width('160px'),
                ToggleColumn::make('is_active')->label('Ativa'),
            ])
            ->headerActions([
                Action::make('gerar')->label('Gerar variações')->icon(Heroicon::OutlinedSquares2x2)
                    ->modalDescription('Cria todas as combinações das opções do produto que ainda não existem.')
                    ->schema([
                        Money::input('price', 'Preço de venda')->required()
                            ->default(fn () => $this->getOwnerRecord()->variants()->value('price')),
                        Money::input('compare_at_price', 'Preço "de" (riscado)')
                            ->default(fn () => $this->getOwnerRecord()->variants()->value('compare_at_price')),
                        TextInput::make('stock')->label('Estoque de cada uma')->numeric()->default(1000)->required(),
                        Toggle::make('remove_default')->label('Remover a variação "Padrão" (sem opções)')->default(true),
                    ])
                    ->action(function (array $data) {
                        $created = $this->generateVariants($data);
                        Notification::make()->title($created ? "$created variação(ões) criada(s)" : 'Todas as combinações já existem')
                            ->body($created ? null : 'Cadastre as opções (Cor, Tamanho...) no formulário acima e salve antes de gerar.')
                            ->success()->send();
                    }),
                CreateAction::make()->label('Nova variação')
                    ->mutateDataUsing(fn (array $data) => $data + ['title' => $this->titleFor($data)]),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => array_merge($data, ['title' => $this->titleFor($data)])),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('preco')->label('Alterar preço')->icon(Heroicon::OutlinedCurrencyDollar)
                        ->schema([Money::input('price', 'Preço de venda')->required(), Money::input('compare_at_price', 'Preço "de" (riscado)')])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['price' => $data['price'], 'compare_at_price' => $data['compare_at_price']])),
                    BulkAction::make('estoque')->label('Definir estoque')->icon(Heroicon::OutlinedCube)
                        ->schema([TextInput::make('stock')->label('Estoque')->numeric()->required()])
                        ->action(fn (Collection $records, array $data) => $records->each(fn ($v) => $v->adjustStock((int) $data['stock'] - $v->stock, 'manual', null, auth()->id()))),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function titleFor(array $data): string
    {
        $parts = array_filter([$data['option1'] ?? null, $data['option2'] ?? null, $data['option3'] ?? null]);

        return $parts ? implode(' / ', $parts) : 'Padrão';
    }

    private function generateVariants(array $data): int
    {
        $product = $this->getOwnerRecord();
        $options = $product->options()->orderBy('position')->get()->values();
        if ($options->isEmpty()) {
            return 0;
        }

        // Produto cartesiano das opções
        $combos = [[]];
        foreach ($options as $opt) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($opt->values as $v) {
                    $next[] = [...$c, $v];
                }
            }
            $combos = $next;
        }

        $existing = $product->variants()->get()->map(fn ($v) => implode('|', array_filter([$v->option1, $v->option2, $v->option3], fn ($x) => $x !== null)))->flip();
        $pos = (int) $product->variants()->max('position');
        $created = 0;
        foreach ($combos as $c) {
            if (isset($existing[implode('|', $c)])) {
                continue;
            }
            $product->variants()->create([
                'title' => implode(' / ', $c),
                'option1' => $c[0] ?? null,
                'option2' => $c[1] ?? null,
                'option3' => $c[2] ?? null,
                'price' => $data['price'],
                'compare_at_price' => $data['compare_at_price'] ?? null,
                'stock' => (int) $data['stock'],
                'position' => ++$pos,
            ]);
            $created++;
        }
        if ($created && ! empty($data['remove_default'])) {
            $product->variants()->whereNull('option1')->where('title', 'Padrão')->delete();
        }

        return $created;
    }
}
