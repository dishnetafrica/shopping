<?php
namespace App\Filament\Resources;

use App\Filament\Resources\BusinessOpportunityResource\Pages;
use App\Models\BusinessOpportunity;
use App\Services\Bot\Opportunity\OpportunityTypes;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Sales view of qualified B2B opportunities captured by the bot. Registered only for
 * tenants running opportunity_mode. The bot fills these in; sales works them.
 */
class BusinessOpportunityResource extends Resource
{
    protected static ?string $model = BusinessOpportunity::class;
    protected static ?string $navigationIcon = 'heroicon-o-briefcase';
    protected static ?string $navigationGroup = 'Sales';
    protected static ?string $navigationLabel = 'Opportunities';
    protected static ?string $modelLabel = 'opportunity';
    protected static ?int $navigationSort = 1;

    /** Only show this when the tenant has the engine switched on. */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) (auth()->user()?->tenant?->setting('opportunity_mode', false));
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) (BusinessOpportunity::query()
            ->whereIn('status', BusinessOpportunity::OPEN_STATUSES)->count() ?: '');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->options(OpportunityTypes::LABELS)->required(),
            Forms\Components\Select::make('status')
                ->options([
                    'qualifying' => 'Qualifying',
                    'qualified'  => 'Qualified',
                    'assigned'   => 'Assigned',
                    'won'        => 'Won',
                    'lost'       => 'Lost',
                ])->required(),
            Forms\Components\TextInput::make('customer_name')->label('Contact name'),
            Forms\Components\TextInput::make('customer_phone')->label('Phone')->disabled(),
            Forms\Components\TextInput::make('assigned_to')->label('Assigned to (phone)'),
            Forms\Components\DateTimePicker::make('next_followup_at')->label('Next follow-up'),
            Forms\Components\TextInput::make('lead_score')->numeric()->disabled(),
            Forms\Components\TextInput::make('size_band')->disabled(),
            Forms\Components\KeyValue::make('profile')->label('Qualification profile')
                ->columnSpanFull()->disabled(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('lead_score', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('lead_score')->label('Score')->sortable()
                    ->badge()
                    ->color(fn ($state) => $state >= 70 ? 'danger' : ($state >= 45 ? 'warning' : 'gray')),
                Tables\Columns\TextColumn::make('size_band')->label('Size')->badge()
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : '—')
                    ->color(fn ($state) => match ($state) {
                        'strategic' => 'danger', 'large' => 'warning', 'medium' => 'info', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->formatStateUsing(fn ($state) => OpportunityTypes::label((string) $state)),
                Tables\Columns\TextColumn::make('customer_name')->label('Contact')->searchable()
                    ->description(fn ($record) => $record->profile['business_name'] ?? null),
                Tables\Columns\TextColumn::make('customer_phone')->label('Phone')->searchable(),
                Tables\Columns\TextColumn::make('profile.area')->label('Area')->toggleable(),
                Tables\Columns\TextColumn::make('profile.monthly_volume')->label('Cartons/mo')->toggleable(),
                Tables\Columns\TextColumn::make('profile.buying_price')->label('Their price')
                    ->formatStateUsing(fn ($state) => $state ? number_format((int) $state) : '—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => match ($state) {
                        'won' => 'success', 'lost' => 'gray', 'qualified', 'assigned' => 'info', default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('assigned_to')->label('Rep')->toggleable(),
                Tables\Columns\TextColumn::make('next_followup_at')->label('Follow-up')->dateTime('M j, H:i')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Started')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->options(OpportunityTypes::LABELS),
                Tables\Filters\SelectFilter::make('status')->options([
                    'qualifying' => 'Qualifying', 'qualified' => 'Qualified',
                    'assigned' => 'Assigned', 'won' => 'Won', 'lost' => 'Lost',
                ]),
                Tables\Filters\SelectFilter::make('size_band')->options([
                    'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large', 'strategic' => 'Strategic',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('won')->label('Won')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn ($record) => $record->isOpen())
                    ->action(fn ($record) => $record->update(['status' => 'won', 'stage' => 'closed'])),
                Tables\Actions\Action::make('lost')->label('Lost')->icon('heroicon-o-x-circle')->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->isOpen())
                    ->action(fn ($record) => $record->update(['status' => 'lost', 'stage' => 'closed'])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBusinessOpportunities::route('/'),
            'edit'  => Pages\EditBusinessOpportunity::route('/{record}/edit'),
        ];
    }
}
