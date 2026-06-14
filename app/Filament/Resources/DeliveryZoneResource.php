<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeliveryZoneResource\Pages;
use App\Models\DeliveryZone;
use App\Models\Rider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Settings -> Delivery Zones (D1). Each zone sets its fee + ETA + how it's matched. */
class DeliveryZoneResource extends Resource
{
    protected static ?string $model = DeliveryZone::class;

    /** Owner workflows live in the Seller Panel now; keep this in /app for super-admins only. */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->is_super_admin);
    }
    protected static ?string $navigationIcon = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Delivery Zones';
    protected static ?string $modelLabel = 'zone';
    protected static ?string $pluralModelLabel = 'Delivery Zones';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Zone')->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(120)
                    ->placeholder('e.g. Zone A — Kisaasi/Kyanja'),
                Forms\Components\TagsInput::make('match_keywords')
                    ->label('Area keywords')
                    ->helperText('Areas customers type, e.g. kisaasi, kyanja, ntinda. Matched in their location text.')
                    ->placeholder('add area'),
                Forms\Components\Toggle::make('active')->default(true),
            ])->columns(1),

            Forms\Components\Section::make('Fee & ETA')->schema([
                Forms\Components\TextInput::make('flat_fee')->numeric()->default(0)->label('Flat fee')->required(),
                Forms\Components\TextInput::make('per_km_fee')->numeric()->nullable()->label('Per-km fee (optional)')
                    ->helperText('Added on top of flat fee, using the distance from the store pin (when the customer shares a pin).'),
                Forms\Components\TextInput::make('min_fee')->numeric()->default(0)->label('Minimum fee'),
                Forms\Components\TextInput::make('free_over')->numeric()->nullable()->label('Free over (subtotal)')
                    ->helperText('If the order subtotal is at least this, delivery is free.'),
                Forms\Components\TextInput::make('eta_minutes')->numeric()->default(45)->label('ETA (minutes)')->required(),
            ])->columns(2),

            Forms\Components\Section::make('Map match & default rider (optional)')->schema([
                Forms\Components\TextInput::make('center_lat')->numeric()->nullable()->label('Center latitude'),
                Forms\Components\TextInput::make('center_lng')->numeric()->nullable()->label('Center longitude'),
                Forms\Components\TextInput::make('radius_m')->numeric()->nullable()->label('Radius (metres)')
                    ->helperText('If a customer shares a map pin inside this circle, this zone matches.'),
                Forms\Components\Select::make('default_rider_id')->label('Default rider')
                    ->options(fn () => Rider::where('active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->nullable(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('flat_fee')->money('UGX', divideBy: 1)->label('Flat fee'),
            Tables\Columns\TextColumn::make('eta_minutes')->suffix(' min')->label('ETA'),
            Tables\Columns\TextColumn::make('match_keywords')->badge()->label('Areas')->limit(40),
            Tables\Columns\ToggleColumn::make('active'),
        ])->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDeliveryZones::route('/'),
            'create' => Pages\CreateDeliveryZone::route('/create'),
            'edit'   => Pages\EditDeliveryZone::route('/{record}/edit'),
        ];
    }
}
