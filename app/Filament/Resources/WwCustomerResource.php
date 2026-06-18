<?php
namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\WinworldModule;
use App\Filament\Resources\WwCustomerResource\Pages;
use App\Models\WwCustomer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WwCustomerResource extends Resource
{
    use WinworldModule;

    protected static ?string $model = WwCustomer::class;
    protected static ?string $navigationIcon = 'heroicon-o-identification';
    protected static ?string $navigationGroup = 'Win World';
    protected static ?string $navigationLabel = 'Customers';
    protected static ?string $modelLabel = 'customer';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('customer_code')->maxLength(60),
            Forms\Components\TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
            Forms\Components\TextInput::make('contact')->maxLength(255),
            Forms\Components\TextInput::make('credit_limit_days')->numeric()->label('Credit limit (days)'),
            Forms\Components\TextInput::make('ageing_balance')->numeric()->prefix('UGX')
                ->helperText('Synced from SAP'),
            Forms\Components\TextInput::make('overdue_days')->numeric()->label('Overdue (days)')
                ->helperText('Above 30 needs MD approval'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('customer_code')->toggleable(),
                Tables\Columns\TextColumn::make('ageing_balance')->money('UGX')->sortable(),
                Tables\Columns\TextColumn::make('overdue_days')->label('Overdue')->badge()
                    ->color(fn($state)=>(int)$state>30?'danger':((int)$state>0?'warning':'success'))
                    ->formatStateUsing(fn($state)=>(int)$state>30 ? "{$state}d · MD approval" : "{$state}d"),
                Tables\Columns\TextColumn::make('credit_limit_days')->label('Limit')->toggleable(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWwCustomers::route('/'),
            'create' => Pages\CreateWwCustomer::route('/create'),
            'edit'   => Pages\EditWwCustomer::route('/{record}/edit'),
        ];
    }
}
