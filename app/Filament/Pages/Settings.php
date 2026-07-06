<?php
namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Support\TenantContext;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?int $navigationSort = 9;
    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    protected function tenant(): Tenant
    {
        return Tenant::findOrFail(app(TenantContext::class)->id());
    }

    public function mount(): void
    {
        $t = $this->tenant();
        $this->form->fill([
            'currency'          => $t->setting('currency', 'UGX'),
            'usd_ugx'           => $t->setting('usd_ugx'),
            'usd_ssp'           => $t->setting('usd_ssp'),
            'discount_pct'      => $t->setting('discount_pct', 0),
            'discount_amt'      => $t->setting('discount_amt', 0),
            'whatsapp_number'   => $t->whatsapp_number,
            'whatsapp_instance' => $t->whatsapp_instance,
            // Special Order (off-catalogue live pricing)
            'special_order_enabled'  => (bool) $t->setting('special_order_enabled', false),
            'special_order_provider' => $t->setting('special_order_provider', config('marketplace.default_provider', 'jumia')),
            'special_order_margin'   => $t->setting('special_order_margin', config('marketplace.default_margin', 0.15)),
            'special_order_ugx_rate' => $t->setting('special_order_ugx_rate', 0),
            'special_order_terms'    => $t->setting('special_order_terms', config('marketplace.default_terms', '')),
            'ledger_enabled'     => (bool) $t->setting('ledger_enabled', false),
            'prices_include_vat' => (bool) $t->setting('prices_include_vat', false),
            'quote_vat_pct'      => $t->setting('quote_vat_pct', 0),
            'credit_terms_days'  => $t->setting('credit_terms_days', 0),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Currency & discount')->schema([
                Forms\Components\TextInput::make('currency')->default('UGX'),
                Forms\Components\TextInput::make('usd_ugx')->numeric()->label('1 USD = UGX'),
                Forms\Components\TextInput::make('usd_ssp')->numeric()->label('1 USD = SSP'),
                Forms\Components\TextInput::make('discount_pct')->numeric()->label('Store discount %'),
                Forms\Components\TextInput::make('discount_amt')->numeric()->label('Store discount amount'),
            ])->columns(3),
            Forms\Components\Section::make('WhatsApp')->schema([
                Forms\Components\TextInput::make('whatsapp_number')->label('Sending number'),
                Forms\Components\TextInput::make('whatsapp_instance')->label('Evolution instance'),
            ])->columns(2),
            Forms\Components\Section::make('Special Order')
                ->description('When a customer asks for something you don\'t stock, quote a special-order price instead of dead-ending. Prices are marked up by your margin; the source is never shown to the customer.')
                ->schema([
                    Forms\Components\Toggle::make('special_order_enabled')
                        ->label('Enable special orders')
                        ->helperText('Off by default. Best for electronics/hardware shops; leave off for pure grocery.'),
                    Forms\Components\Select::make('special_order_provider')
                        ->label('Price source')
                        ->options(collect(array_keys((array) config('marketplace.providers', [])))
                            ->mapWithKeys(fn ($k) => [$k => ucfirst($k)])->all() ?: ['jumia' => 'Jumia'])
                        ->default(config('marketplace.default_provider', 'jumia'))
                        ->native(false),
                    Forms\Components\TextInput::make('special_order_margin')
                        ->label('Markup')->numeric()->step('0.01')
                        ->helperText('Fraction: 0.15 = +15%.'),
                    Forms\Components\TextInput::make('special_order_ugx_rate')
                        ->label('UGX per 1 USD')->numeric()
                        ->helperText('Set only if you quote special orders in USD. Leave 0 to quote in UGX.'),
                    Forms\Components\Textarea::make('special_order_terms')
                        ->label('Special-order terms shown to the customer')
                        ->rows(2)->columnSpanFull(),
                ])->columns(2),
            Forms\Components\Section::make('Accounting / Ledger')
                ->description('Keep automatic double-entry books — no commands needed.')
                ->collapsed()
                ->schema([
                    Forms\Components\Toggle::make('ledger_enabled')
                        ->label('Keep automatic books')
                        ->helperText('When on, every delivered order and customer payment is recorded in the accounting ledger (receivables, VAT, cash) — giving you a trial balance, debtor aging and statements.'),
                    Forms\Components\Toggle::make('prices_include_vat')
                        ->label('My prices already include VAT')
                        ->helperText('On for VAT-inclusive price lists. The books back the VAT out so totals match your website.'),
                    Forms\Components\TextInput::make('quote_vat_pct')
                        ->numeric()->suffix('%')->label('VAT rate')
                        ->helperText('e.g. 18 for Uganda. 0 = no VAT.'),
                    Forms\Components\TextInput::make('credit_terms_days')
                        ->numeric()->suffix('days')->label('Payment terms')
                        ->helperText('Days a customer has to pay after delivery (drives overdue aging). 0 = due immediately.'),
                ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $t = $this->tenant();
        $t->settings = array_merge($t->settings ?? [], [
            'currency' => $d['currency'], 'usd_ugx' => $d['usd_ugx'], 'usd_ssp' => $d['usd_ssp'],
            'discount_pct' => $d['discount_pct'], 'discount_amt' => $d['discount_amt'],
            'special_order_enabled'  => (bool) ($d['special_order_enabled'] ?? false),
            'special_order_provider' => $d['special_order_provider'] ?? config('marketplace.default_provider', 'jumia'),
            'special_order_margin'   => (float) ($d['special_order_margin'] ?? config('marketplace.default_margin', 0.15)),
            'special_order_ugx_rate' => (int) ($d['special_order_ugx_rate'] ?? 0),
            'special_order_terms'    => (string) ($d['special_order_terms'] ?? ''),
            'ledger_enabled'     => (bool) ($d['ledger_enabled'] ?? false),
            'prices_include_vat' => (bool) ($d['prices_include_vat'] ?? false),
            'quote_vat_pct'      => (float) ($d['quote_vat_pct'] ?? 0),
            'credit_terms_days'  => (int) ($d['credit_terms_days'] ?? 0),
        ]);
        $t->whatsapp_number = $d['whatsapp_number'];
        $t->whatsapp_instance = $d['whatsapp_instance'];
        $t->save();
        Notification::make()->title('Settings saved')->success()->send();
    }
}
