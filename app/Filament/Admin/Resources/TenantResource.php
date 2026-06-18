<?php
namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\TenantResource\Pages;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalogue\ProductImporter;
use App\Support\TenantContext;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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

            Forms\Components\Section::make('Owner login')
                ->description('Lets the shop owner sign in at /app. They log in with this WhatsApp number via a one-time code (no password). Fill it in and Save — the login is created automatically. (To edit or add more logins later, use the Logins table below.)')
                ->schema([
                    Forms\Components\TextInput::make('owner_name')->label('Owner name'),
                    Forms\Components\TextInput::make('owner_phone')->label('Owner WhatsApp number')->tel()
                        ->helperText('Full intl format, e.g. 256772123456. The login code is delivered over this shop’s WhatsApp, so its instance must be connected.'),
                ])->columns(2)->visibleOn('create'),

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
                Forms\Components\Toggle::make('settings.feature_thali')
                    ->label('Daily Thali (set-meal) feature')
                    ->helperText('Show the Daily Thali tool in this seller\'s panel. Turn on for food / restaurant sellers only.')
                    ->afterStateHydrated(function ($component, $state, $record) {
                        if ($state === null && $record) {
                            $cfg = data_get($record->settings, 'thali', []);
                            $component->state(! empty($cfg['enabled']) || ! empty($cfg['days']));
                        }
                    }),
                Forms\Components\Toggle::make('settings.feature_image_search')
                    ->label('Photo product search')
                    ->default(true)
                    ->helperText('Let customers find products by sending a photo on WhatsApp (uses AI vision; needs an OpenAI key).')
                    ->afterStateHydrated(function ($component, $state) {
                        if ($state === null) $component->state(true);
                    }),
            ])->columns(3),

            Forms\Components\Section::make('Leads & assignment')->schema([
                Forms\Components\Select::make('settings.lead_assignment_mode')
                    ->label('Assignment mode')
                    ->options([
                        'round_robin' => 'Round-robin (auto, one owner each)',
                        'claim'       => 'Claim (first to reply CLAIM wins)',
                        'manual'      => 'Manual (manager assigns)',
                    ])
                    ->default('round_robin'),
                Forms\Components\Repeater::make('settings.lead_recipients')
                    ->label('Lead / ticket recipients')
                    ->helperText('Who gets new-lead alerts. Sales handle leads; Support handle service issues; Manager receives manual-mode assignments.')
                    ->schema([
                        Forms\Components\TextInput::make('phone')->label('WhatsApp number')->tel()
                            ->helperText('Intl format, e.g. 256772123456'),
                        Forms\Components\Select::make('role')->options([
                            'sales' => 'Sales', 'support' => 'Support', 'manager' => 'Manager',
                        ])->default('sales'),
                        Forms\Components\TextInput::make('name')->label('Name'),
                    ])->columns(3)->default([])->addActionLabel('Add recipient'),
            ])->columns(1),
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
            Tables\Actions\Action::make('importProducts')
                ->label('Import products')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->modalHeading(fn (Tenant $record) => 'Import products into ' . $record->name)
                ->modalDescription('Upload a pricelist CSV (name, price, category, keywords, stock — or a standard POS export). Imports straight into this business.')
                ->form([
                    Forms\Components\FileUpload::make('file')->label('CSV file')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])
                        ->storeFiles(false)->required(),
                    Forms\Components\Toggle::make('replace')->label('Replace the whole catalogue')
                        ->helperText('On: clears this business’s products first, then loads the file (recommended for a full pricelist). Off: adds/updates by name.')
                        ->default(true),
                ])
                ->action(function (Tenant $record, array $data) {
                    $r = self::importCsvForTenant($record, $data['file'], (bool) ($data['replace'] ?? true));
                    if (isset($r['error'])) {
                        Notification::make()->title('Import failed')->body($r['error'])->danger()->send();
                        return;
                    }
                    $body = ! empty($r['updated'])
                        ? "Created {$r['created']}, updated {$r['updated']}"
                        : "Loaded {$r['created']} products.";
                    Notification::make()->title('Import complete — ' . $record->name)->body($body)->success()->send();
                }),
            Tables\Actions\EditAction::make(),
        ]);
    }

    /** Run a CSV import scoped to one tenant (used by both the list-row and edit-page buttons). */
    public static function importCsvForTenant(Tenant $tenant, mixed $file, bool $replace): array
    {
        $f = is_array($file) ? reset($file) : $file;
        $path = is_object($f) && method_exists($f, 'getRealPath') ? $f->getRealPath()
            : (is_string($f) ? $f : null);
        if (! $path) return ['error' => 'Could not read the uploaded file'];

        // Scope to THIS tenant so the importer's delete/insert only touches this business, then restore.
        $ctx = app(TenantContext::class);
        $ctx->asSuperAdmin(false);
        $ctx->set($tenant->id);
        try {
            return app(ProductImporter::class)->importCsv($path, $replace ? 'replace' : 'merge');
        } finally {
            $ctx->set(null);
            $ctx->asSuperAdmin(true);
        }
    }

    public static function getRelations(): array
    {
        return [
            TenantResource\RelationManagers\UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }

    /** The shop-owner login for a tenant (the 'owner' user, else the first non-admin user). */
    public static function ownerOf(Tenant $tenant): ?User
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->first();
    }

    /** Create or update the owner login from the form fields. No-op if no phone given. */
    public static function upsertOwner(Tenant $tenant, ?string $name, ?string $phone): ?User
    {
        $phone = trim((string) $phone);
        if ($phone === '') return null;

        $user = self::ownerOf($tenant) ?? new User(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $isNew = ! $user->exists;
        $user->tenant_id = $tenant->id;
        if (empty($user->role)) $user->role = 'owner';
        if (! empty($name)) $user->name = $name;
        $user->phone = $phone;
        $user->is_super_admin = false;
        // OTP-only login has no password, but the column is NOT NULL — fill a random one on create.
        if ($isNew) $user->password = \Illuminate\Support\Str::random(40);
        $user->save();

        return $user;
    }
}
