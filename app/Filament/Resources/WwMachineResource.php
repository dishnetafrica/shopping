<?php
namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\WinworldModule;
use App\Filament\Resources\WwMachineResource\Pages;
use App\Models\WwMachine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WwMachineResource extends Resource
{
    use WinworldModule;

    protected static ?string $model = WwMachine::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'Win World';
    protected static ?string $navigationLabel = 'Machines';
    protected static ?string $modelLabel = 'machine';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('process')
                ->options(['Extrusion'=>'Extrusion','Printing'=>'Printing','Cutting'=>'Cutting'])->required(),
            Forms\Components\TextInput::make('machine')->required()->maxLength(30)->helperText('e.g. ABA, A-1, FP-01'),
            Forms\Components\TextInput::make('max_speed')->numeric()->label('Max speed'),
            Forms\Components\Select::make('speed_type')
                ->options(['Meter/Min'=>'Meter/Min','Pcs/Min'=>'Pcs/Min','Stroke/Min'=>'Stroke/Min'])->default('Meter/Min'),
            Forms\Components\TextInput::make('cavity_repeat_pcs')->numeric()->label('Cavity / repeat pcs'),
            Forms\Components\TextInput::make('remarks')->maxLength(255),
            Forms\Components\Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('process')
            ->columns([
                Tables\Columns\TextColumn::make('process')->badge()->sortable()
                    ->color(fn($state)=>match($state){'Extrusion'=>'warning','Printing'=>'info','Cutting'=>'success',default=>'gray'}),
                Tables\Columns\TextColumn::make('machine')->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('max_speed')->label('Max speed')->placeholder('—'),
                Tables\Columns\TextColumn::make('speed_type')->toggleable(),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('process')->options(['Extrusion'=>'Extrusion','Printing'=>'Printing','Cutting'=>'Cutting']),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWwMachines::route('/'),
            'create' => Pages\CreateWwMachine::route('/create'),
            'edit'   => Pages\EditWwMachine::route('/{record}/edit'),
        ];
    }
}
