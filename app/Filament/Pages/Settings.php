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
        ])->statePath('data');
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $t = $this->tenant();
        $t->settings = array_merge($t->settings ?? [], [
            'currency' => $d['currency'], 'usd_ugx' => $d['usd_ugx'], 'usd_ssp' => $d['usd_ssp'],
            'discount_pct' => $d['discount_pct'], 'discount_amt' => $d['discount_amt'],
        ]);
        $t->whatsapp_number = $d['whatsapp_number'];
        $t->whatsapp_instance = $d['whatsapp_instance'];
        $t->save();
        Notification::make()->title('Settings saved')->success()->send();
    }
}
