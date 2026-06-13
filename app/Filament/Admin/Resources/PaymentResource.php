<?php
namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $modelLabel = 'payment';
    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([]); // read-only
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')->label('Business')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('provider')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'flutterwave' ? 'Mobile Money' : ($state === 'stripe' ? 'Card' : $state))
                    ->color(fn (string $state) => $state === 'stripe' ? 'info' : 'success'),
                Tables\Columns\TextColumn::make('plan')->badge(),
                Tables\Columns\TextColumn::make('amount')->money(fn (Payment $r) => $r->currency)->sortable(),
                Tables\Columns\TextColumn::make('network')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('phone')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'successful' => 'success', 'failed' => 'danger', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('tx_ref')->label('Ref')->copyable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(['successful' => 'Successful', 'pending' => 'Pending', 'failed' => 'Failed']),
                Tables\Filters\SelectFilter::make('provider')->options(['flutterwave' => 'Mobile Money', 'stripe' => 'Card']),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/')];
    }

    public static function canCreate(): bool { return false; }
}
