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
                Forms\Components\Select::make('status')->options(['active'=>'Active','suspended'=>'Suspended'])->default('active'),
            ])->columns(2),

            Forms\Components\Section::make('Plan & billing')->schema([
                Forms\Components\Select::make('plan')
                    ->options(['free'=>'Free','starter'=>'Starter ($20/mo)','pro'=>'Pro ($50/mo)'])
                    ->default('free')->required()
                    ->helperText('What they pay for. During an active trial they get full Pro features regardless.'),
                Forms\Components\DateTimePicker::make('trial_ends_at')
                    ->label('Trial ends')->helperText('Full features until this date. Leave set to +30 days for new shops; clear it to end the trial.'),
                Forms\Components\DateTimePicker::make('paid_until')
                    ->label('Paid until')->helperText('After they pay (e.g. Mobile Money), set this to one month ahead. If it lapses, the plan auto-drops to Free.'),
                Forms\Components\TextInput::make('billing_note')
                    ->label('Billing note')->helperText('e.g. "MTN MoMo UGX 185,000 — 13 Jun"')->maxLength(190),
            ])->columns(2),

            Forms\Components\Section::make('WhatsApp')->schema([
                Forms\Components\Select::make('whatsapp_driver')->options(['evolution'=>'Evolution','cloud'=>'Cloud API'])->default('evolution'),
                Forms\Components\TextInput::make('whatsapp_instance')->label('Instance / phone id')
                    ->helperText('The Evolution instance (or Cloud phone id) that receives this business\u2019s messages.'),
                Forms\Components\TextInput::make('whatsapp_number')->label('WhatsApp number'),
            ])->columns(3),

            Forms\Components\Section::make('Settings')->schema([
                Forms\Components\TextInput::make('settings.owner_alert_phone')
                    ->label('Owner alert WhatsApp')
                    ->helperText('New-order alerts & payment receipts go here. Full intl format e.g. 256772123456. Comma-separate for several.')
                    ->columnSpanFull(),
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
            Tables\Columns\TextColumn::make('plan')->badge()
                ->color(fn (string $state) => match ($state) { 'pro' => 'success', 'starter' => 'warning', default => 'gray' }),
            Tables\Columns\TextColumn::make('trial_ends_at')->label('Trial ends')->date()->placeholder('—'),
            Tables\Columns\TextColumn::make('paid_until')->label('Paid until')->date()->placeholder('—')
                ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'danger'),
            Tables\Columns\TextColumn::make('orders_count')->counts('orders')->label('Orders'),
        ])->actions([
            Tables\Actions\Action::make('markPaid')
                ->label('Mark paid 1 month')->icon('heroicon-o-banknotes')->color('success')
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Select::make('plan')->options(['starter'=>'Starter ($20)','pro'=>'Pro ($50)'])->default('pro')->required(),
                    Forms\Components\TextInput::make('note')->label('Payment note')->placeholder('MTN MoMo ref / amount'),
                ])
                ->action(function (Tenant $record, array $data) {
                    $base = ($record->paid_until && $record->paid_until->isFuture()) ? $record->paid_until : now();
                    $record->plan = $data['plan'];
                    $record->paid_until = $base->copy()->addMonth();
                    $record->trial_ends_at = null; // trial over once they're paying
                    if (! empty($data['note'])) $record->billing_note = $data['note'];
                    $record->save();
                }),
            Tables\Actions\Action::make('startTrial')
                ->label('Start 30-day trial')->icon('heroicon-o-gift')->color('warning')
                ->requiresConfirmation()
                ->action(function (Tenant $record) {
                    $record->trial_ends_at = now()->addDays(30);
                    $record->save();
                }),
            Tables\Actions\EditAction::make(),
        ]);
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
