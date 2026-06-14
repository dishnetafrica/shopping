<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderNotificationRecipientResource\Pages;
use App\Models\OrderNotificationRecipient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Settings -> Order Notifications. The store configures one or more WhatsApp
 * numbers that receive a message the moment a new order is placed.
 */
class OrderNotificationRecipientResource extends Resource
{
    protected static ?string $model = OrderNotificationRecipient::class;

    /** Owner workflows live in the Seller Panel now; keep this in /app for super-admins only. */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->is_super_admin);
    }
    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Order Notifications';
    protected static ?string $modelLabel = 'recipient';
    protected static ?string $pluralModelLabel = 'Order Notifications';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->description('These WhatsApp numbers get a message automatically when a new order is placed.')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Name / role')
                        ->required()
                        ->placeholder('e.g. Owner, Manager, Kitchen, Dispatch')
                        ->maxLength(120),

                    Forms\Components\TextInput::make('phone')
                        ->label('WhatsApp number')
                        ->required()
                        ->tel()
                        ->placeholder('256700111111')
                        ->helperText('Country code + number. Must be a number your shop WhatsApp can message.')
                        ->maxLength(32),

                    Forms\Components\Toggle::make('active')
                        ->default(true)
                        ->helperText('Off = this number stops receiving notifications (kept for later).'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->searchable()->copyable(),
                Tables\Columns\ToggleColumn::make('active'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No recipients yet')
            ->emptyStateDescription('Add the WhatsApp numbers that should receive new-order alerts.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListOrderNotificationRecipients::route('/'),
            'create' => Pages\CreateOrderNotificationRecipient::route('/create'),
            'edit'   => Pages\EditOrderNotificationRecipient::route('/{record}/edit'),
        ];
    }
}
