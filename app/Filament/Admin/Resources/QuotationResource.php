<?php
namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\QuotationResource\Pages;
use App\Models\Quotation;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Admin oversight of every tenant's quotations and their lifecycle status.
 * Read-only + a quick status setter. Resend/convert stay in the seller panel
 * (those need the tenant's own WhatsApp + order pipeline).
 */
class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 35;
    protected static ?string $modelLabel = 'quotation';

    public static function canCreate(): bool
    {
        return false;
    }

    protected static function statusColor(?string $s): string
    {
        return [
            'sent'      => 'info',
            'accepted'  => 'success',
            'declined'  => 'danger',
            'converted' => 'warning',
            'expired'   => 'gray',
        ][$s] ?? 'gray';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('quote_no')->label('Quote')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('tenant.name')->label('Business')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('customer_name')->label('Customer')->searchable()
                    ->description(fn ($record) => $record->customer_phone),
                Tables\Columns\TextColumn::make('total')->label('Total')
                    ->formatStateUsing(fn ($state, $record) => $record->currency . ' ' . number_format((float) $state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => static::statusColor($state)),
                Tables\Columns\TextColumn::make('source')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('valid_until')->date()->label('Valid until')->sortable(),
                Tables\Columns\TextColumn::make('send_count')->label('Sends')->alignCenter(),
                Tables\Columns\TextColumn::make('order.order_no')->label('Order')->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->label('Sent'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined',
                    'converted' => 'Converted', 'expired' => 'Expired',
                ]),
                Tables\Filters\SelectFilter::make('source')->options(['panel' => 'Panel', 'bot' => 'Bot']),
                Tables\Filters\SelectFilter::make('tenant')->relationship('tenant', 'name')->label('Business')->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('setStatus')
                    ->label('Status')
                    ->icon('heroicon-o-flag')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->options(['sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired'])
                            ->required(),
                    ])
                    ->action(function (Quotation $record, array $data) {
                        if ($record->status === 'converted') {
                            Notification::make()->title('Already an order')->warning()->send();
                            return;
                        }
                        $record->update(['status' => $data['status']]);
                        Notification::make()->title('Status updated')->success()->send();
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Quotation')->schema([
                Infolists\Components\TextEntry::make('quote_no')->label('Quote no')->copyable(),
                Infolists\Components\TextEntry::make('tenant.name')->label('Business'),
                Infolists\Components\TextEntry::make('status')->badge()->color(fn ($state) => static::statusColor($state)),
                Infolists\Components\TextEntry::make('customer_name')->label('Customer')->placeholder('—'),
                Infolists\Components\TextEntry::make('customer_phone')->label('Phone'),
                Infolists\Components\TextEntry::make('source')->badge()->color('gray'),
                Infolists\Components\TextEntry::make('total')
                    ->formatStateUsing(fn ($state, $record) => $record->currency . ' ' . number_format((float) $state)),
                Infolists\Components\TextEntry::make('valid_until')->date()->label('Valid until'),
                Infolists\Components\TextEntry::make('send_count')->label('Times sent'),
                Infolists\Components\TextEntry::make('order.order_no')->label('Converted to order')->placeholder('—'),
                Infolists\Components\TextEntry::make('created_at')->dateTime()->label('Sent'),
                Infolists\Components\TextEntry::make('last_sent_at')->dateTime()->label('Last sent')->placeholder('—'),
            ])->columns(3),

            Infolists\Components\Section::make('Items')->schema([
                Infolists\Components\RepeatableEntry::make('items')->hiddenLabel()->schema([
                    Infolists\Components\TextEntry::make('name')->label('Item')->columnSpan(2),
                    Infolists\Components\TextEntry::make('qty')->label('Qty'),
                    Infolists\Components\TextEntry::make('unit_price')->label('Unit')
                        ->formatStateUsing(fn ($state) => number_format((float) $state)),
                    Infolists\Components\TextEntry::make('line_total')->label('Amount')
                        ->formatStateUsing(fn ($state) => number_format((float) $state)),
                ])->columns(5),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotations::route('/'),
            'view'  => Pages\ViewQuotation::route('/{record}'),
        ];
    }
}
