<?php
namespace App\Filament\Resources\BusinessOpportunityResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** The next-actions for one opportunity — reps work the call list from here. */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';
    protected static ?string $title = 'Tasks';
    protected static ?string $recordTitleAttribute = 'title';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->required()->columnSpanFull(),
            Forms\Components\Textarea::make('reason')->columnSpanFull(),
            Forms\Components\TextInput::make('assigned_to')->label('Assigned to (phone)'),
            Forms\Components\DateTimePicker::make('due_at')->label('Due'),
            Forms\Components\Select::make('status')->options([
                'open' => 'Open', 'done' => 'Done', 'skipped' => 'Skipped',
            ])->default('open')->required(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('due_at', 'asc')
            ->columns([
                Tables\Columns\IconColumn::make('status')
                    ->icon(fn ($state) => match ($state) {
                        'done' => 'heroicon-o-check-circle',
                        'skipped' => 'heroicon-o-minus-circle',
                        default => 'heroicon-o-clock',
                    })
                    ->color(fn ($state) => match ($state) {
                        'done' => 'success', 'skipped' => 'gray', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('title')->weight('medium')
                    ->description(fn ($record) => $record->reason),
                Tables\Columns\TextColumn::make('assigned_to')->label('Rep'),
                Tables\Columns\TextColumn::make('due_at')->dateTime('M j, H:i')->sortable(),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\Action::make('done')->label('Done')->icon('heroicon-o-check')->color('success')
                    ->visible(fn ($record) => $record->status === 'open')
                    ->action(fn ($record) => $record->update(['status' => 'done', 'done_at' => now()])),
                Tables\Actions\EditAction::make(),
            ]);
    }
}
