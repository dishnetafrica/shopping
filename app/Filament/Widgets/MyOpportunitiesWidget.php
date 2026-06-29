<?php
namespace App\Filament\Widgets;

use App\Filament\Resources\BusinessOpportunityResource;
use App\Models\BusinessOpportunity;
use App\Services\Bot\Opportunity\OpportunityTypes;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rep's open book of business — opportunities assigned to them, hottest first.
 * Managers (owner/admin/manager) see the whole pipeline. Click through to work it.
 */
class MyOpportunitiesWidget extends BaseWidget
{
    protected static ?string $heading = 'My opportunities';
    protected static ?int $sort = -2;
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) (auth()->user()?->tenant?->setting('opportunity_mode', false));
    }

    public function getTableHeading(): string
    {
        $u = auth()->user();
        $seeAll = $u && ($u->isOwnerLike() || (string) $u->role === 'manager');
        return $seeAll ? 'Pipeline' : 'My opportunities';
    }

    public function table(Table $table): Table
    {
        $u       = auth()->user();
        $seeAll  = $u && ($u->isOwnerLike() || (string) $u->role === 'manager');
        $myPhone = $u ? preg_replace('/[^0-9]/', '', (string) $u->phone) : '';

        return $table
            ->query(
                BusinessOpportunity::query()
                    ->whereIn('status', BusinessOpportunity::OPEN_STATUSES)
                    ->when(! $seeAll && $myPhone !== '', fn (Builder $q) => $q->where('assigned_to', $myPhone))
                    ->orderByDesc('lead_score')
                    ->orderBy('next_followup_at')
            )
            ->recordUrl(fn ($record) => BusinessOpportunityResource::getUrl('edit', ['record' => $record]))
            ->columns([
                Tables\Columns\TextColumn::make('lead_score')->label('Score')->badge()->sortable()
                    ->color(fn ($state) => $state >= 70 ? 'danger' : ($state >= 45 ? 'warning' : 'gray')),
                Tables\Columns\TextColumn::make('customer_name')->label('Contact')->searchable()
                    ->description(fn ($record) => $record->profile['business_name'] ?? null),
                Tables\Columns\TextColumn::make('type')->badge()
                    ->formatStateUsing(fn ($state) => OpportunityTypes::label((string) $state)),
                Tables\Columns\TextColumn::make('size_band')->label('Size')->badge()
                    ->formatStateUsing(fn ($state) => $state ? ucfirst((string) $state) : '—')
                    ->color(fn ($state) => match ($state) {
                        'strategic' => 'danger', 'large' => 'warning', 'medium' => 'info', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('profile.monthly_volume')->label('Cartons/mo'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('next_followup_at')->label('Follow-up')->dateTime('M j, H:i')->sortable(),
            ])
            ->paginated([10, 25]);
    }
}
