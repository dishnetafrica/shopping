<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeliveryResource\Pages;
use App\Models\Delivery;
use App\Models\Rider;
use App\Models\Tenant;
use App\Services\Delivery\DeliveryService;
use App\Support\TenantContext;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Delivery Board (D2). Status-filtered list with assign + advance actions. */
class DeliveryResource extends Resource
{
    protected static ?string $model = Delivery::class;

    /** Owner workflows live in the Seller Panel now; keep this in /app for super-admins only. */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->is_super_admin);
    }
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Delivery Board';
    protected static ?string $modelLabel = 'delivery';
    protected static ?string $pluralModelLabel = 'Delivery Board';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order.order_no')->label('Order')->searchable(),
                Tables\Columns\TextColumn::make('order.customer_name')->label('Customer')->searchable(),
                Tables\Columns\TextColumn::make('order.location')->label('Drop')->limit(24),
                Tables\Columns\TextColumn::make('zone.name')->label('Zone')->badge(),
                Tables\Columns\TextColumn::make('fee')->label('Fee')->money('UGX', divideBy: 1),
                Tables\Columns\TextColumn::make('cod_amount')->label('COD')->money('UGX', divideBy: 1),
                Tables\Columns\TextColumn::make('eta_at')->label('ETA')->dateTime('H:i'),
                Tables\Columns\TextColumn::make('rider.name')->label('Rider'),
                Tables\Columns\TextColumn::make('status')->badge()->colors([
                    'gray' => 'assigned', 'info' => 'picked', 'warning' => 'out',
                    'success' => 'delivered', 'danger' => 'failed',
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'assigned' => 'Assigned', 'picked' => 'Picked', 'out' => 'Out for delivery',
                    'delivered' => 'Delivered', 'failed' => 'Failed',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('assign')
                    ->label('Assign rider')->icon('heroicon-o-user-plus')
                    ->form([
                        Forms\Components\Select::make('rider')->label('Rider')
                            ->options(fn () => Rider::where('active', true)->orderBy('name')->pluck('name', 'name')->all())
                            ->searchable()->required(),
                    ])
                    ->action(function (Delivery $record, array $data) {
                        $rider = Rider::where('name', $data['rider'])->first();
                        $tenant = Tenant::find(app(TenantContext::class)->id());
                        app(DeliveryService::class)->assign($tenant, $record->order, $data['rider'], (string) ($rider->phone ?? ''));
                    }),
                Tables\Actions\Action::make('advance')
                    ->label('Update status')->icon('heroicon-o-arrow-right-circle')
                    ->form([
                        Forms\Components\Select::make('to')->label('New status')->required()->options([
                            'picked' => 'Picked up', 'out' => 'Out for delivery',
                            'delivered' => 'Delivered', 'failed' => 'Failed',
                        ]),
                        Forms\Components\TextInput::make('recipient_name')->label('Received by (optional)'),
                        Forms\Components\Toggle::make('cod_collected')->label('Cash collected'),
                        Forms\Components\TextInput::make('reason')->label('Reason (if failed)'),
                    ])
                    ->action(function (Delivery $record, array $data) {
                        $tenant = Tenant::find(app(TenantContext::class)->id());
                        app(DeliveryService::class)->advance($tenant, $record, $data['to'], $data);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListDeliveries::route('/')];
    }
}
