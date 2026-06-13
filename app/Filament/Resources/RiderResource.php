<?php
namespace App\Filament\Resources;

use App\Filament\Resources\RiderResource\Pages;
use App\Models\Rider;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RiderResource extends Resource
{
    protected static ?string $model = Rider::class;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\TextInput::make('phone')->tel(),
            Forms\Components\Toggle::make('active')->default(true),
            Forms\Components\FileUpload::make('photo')
                ->image()->avatar()->imageEditor()
                ->directory('riders')->disk('public')
                ->helperText('Shown to the customer on delivery.'),
            Forms\Components\TextInput::make('city'),
            Forms\Components\DatePicker::make('dob')->label('Date of birth'),
            Forms\Components\TextInput::make('address')->columnSpanFull(),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\ImageColumn::make('photo')->circular()->disk('public')->label(''),
            Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('phone'),
            Tables\Columns\TextColumn::make('city')->toggleable(),
            Tables\Columns\IconColumn::make('active')->boolean(),
        ])->actions([Tables\Actions\EditAction::make()])
          ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRiders::route('/'),
            'create' => Pages\CreateRider::route('/create'),
            'edit'   => Pages\EditRider::route('/{record}/edit'),
        ];
    }
}
