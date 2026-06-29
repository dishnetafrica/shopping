<?php
namespace App\Filament\Widgets;

use App\Models\BusinessOpportunity;
use App\Models\OpportunityTask;
use App\Services\Bot\Opportunity\OpportunityTypes;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Today's Priorities" — open opportunity tasks that are due now or overdue, highest
 * lead score first. Sales opens the dashboard and immediately sees whom to call.
 */
class TodaysPrioritiesWidget extends BaseWidget
{
    protected static ?string $heading = "Today's priorities";
    protected static ?int $sort = -3;
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) (auth()->user()?->tenant?->setting('opportunity_mode', false));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OpportunityTask::query()
                    ->where('status', 'open')
                    ->where(fn (Builder $q) => $q->whereNull('due_at')->orWhere('due_at', '<=', now()->endOfDay()))
                    ->orderByDesc('priority')
                    ->orderBy('due_at')
            )
            ->columns([
                Tables\Columns\TextColumn::make('priority')->label('★')
                    ->formatStateUsing(fn ($state) => str_repeat('★', max(1, (int) ceil(((int) $state) / 20))))
                    ->color(fn ($state) => $state >= 70 ? 'danger' : ($state >= 45 ? 'warning' : 'gray')),
                Tables\Columns\TextColumn::make('title')->label('Action')->weight('medium')
                    ->description(fn ($record) => $record->reason),
                Tables\Columns\TextColumn::make('opportunity.type')->label('Type')->badge()
                    ->formatStateUsing(fn ($state) => $state ? OpportunityTypes::label((string) $state) : '—'),
                Tables\Columns\TextColumn::make('opportunity.size_band')->label('Size')->badge()
                    ->formatStateUsing(fn ($state) => $state ? ucfirst((string) $state) : '—'),
                Tables\Columns\TextColumn::make('opportunity.profile.area')->label('Area'),
                Tables\Columns\TextColumn::make('opportunity.profile.buying_price')->label('Competitor price')
                    ->formatStateUsing(fn ($state) => $state ? number_format((int) $state) : '—'),
                Tables\Columns\TextColumn::make('assigned_to')->label('Rep')->toggleable(),
                Tables\Columns\TextColumn::make('due_at')->label('Due')->dateTime('M j, H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('done')->label('Done')->icon('heroicon-o-check')->color('success')
                    ->action(fn (OpportunityTask $record) => $record->update(['status' => 'done', 'done_at' => now()])),
            ])
            ->paginated([10, 25]);
    }
}
