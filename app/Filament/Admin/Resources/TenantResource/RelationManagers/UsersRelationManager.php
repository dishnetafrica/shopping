<?php

namespace App\Filament\Admin\Resources\TenantResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Full CRUD over a business's logins (shop owner + staff). These are OTP-only logins
 * (no password); they are always non-super-admin so a shop login can never reach the
 * operator panel. Creating here auto-stamps tenant_id via the relationship.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';
    protected static ?string $title = 'Logins';
    protected static ?string $modelLabel = 'login';
    protected static ?string $icon = 'heroicon-o-key';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\TextInput::make('phone')->label('WhatsApp number')->tel()->required()
                ->helperText('Full intl format e.g. 256772123456. They sign in at /app with this via a one-time WhatsApp code.'),
            Forms\Components\TextInput::make('email')->email()->label('Email (optional)'),
            Forms\Components\TextInput::make('password')->password()->revealable()
                ->label('Password (optional)')
                ->helperText('Set this only if they’ll sign in by email + password. Leave blank for WhatsApp-code login.')
                ->dehydrated(fn ($state) => filled($state)),
            Forms\Components\Select::make('role')
                ->options(['owner' => 'Owner', 'staff' => 'Staff'])
                ->default('owner')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('WhatsApp')->searchable(),
                Tables\Columns\TextColumn::make('email')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('role')->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add login')
                    ->mutateFormDataUsing(function (array $data) {
                        $data['is_super_admin'] = false;
                        // OTP login has no password; the column is NOT NULL, so fill a random one.
                        if (empty($data['password'])) $data['password'] = \Illuminate\Support\Str::random(40);
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data) => array_merge($data, ['is_super_admin' => false])),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
