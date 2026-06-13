<?php
namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $modelLabel = 'business';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Business')->schema([
                Forms\Components\TextInput::make('name')->required(),
                Forms\Components\TextInput::make('slug')->required()->helperText('Subdomain, e.g. "acme" → acme.yourdomain.com')
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('order_prefix')->default('ORD')->maxLength(6),
                Forms\Components\Select::make('plan')->options(['starter'=>'Starter','pro'=>'Pro'])->default('starter'),
                Forms\Components\Select::make('status')->options(['active'=>'Active','suspended'=>'Suspended'])->default('active'),
            ])->columns(2),

            Forms\Components\Section::make('WhatsApp')->schema([
                Forms\Components\Select::make('whatsapp_driver')->options(['evolution'=>'Evolution','cloud'=>'Cloud API'])->default('evolution'),
                Forms\Components\TextInput::make('whatsapp_instance')->label('Instance / phone id')
                    ->helperText('The Evolution instance (or Cloud phone id) that receives this business\u2019s messages.'),
                Forms\Components\TextInput::make('whatsapp_number')->label('WhatsApp number'),
            ])->columns(3),

            Forms\Components\Section::make('Settings')->schema([
                Forms\Components\TextInput::make('settings.currency')->default('UGX'),
                Forms\Components\TextInput::make('settings.usd_ugx')->numeric()->label('1 USD = UGX'),
                Forms\Components\TextInput::make('settings.usd_ssp')->numeric()->label('1 USD = SSP'),
                Forms\Components\TextInput::make('settings.discount_pct')->numeric()->label('Discount %'),
                Forms\Components\TextInput::make('settings.discount_amt')->numeric()->label('Discount amount'),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('slug')->badge(),
            Tables\Columns\TextColumn::make('whatsapp_number')->label('WhatsApp'),
            Tables\Columns\TextColumn::make('plan')->badge(),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn ($s) => $s === 'active' ? 'success' : 'danger'),
            Tables\Columns\TextColumn::make('orders_count')->counts('orders')->label('Orders'),
        ])->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
