<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * The staff panel's user form: name, email and locale, and nothing else.
 *
 * Every credential surface is deliberately absent: no `password`, no
 * `two_factor_secret`, no `two_factor_recovery_codes`, no API token. A staff
 * member who needs to change a user's password triggers the product's own
 * reset flow instead, which keeps the one credential path this product
 * already has (and audits) the only one that exists. Pinned by
 * `tests/Feature/Admin/UserResourceTest.php`, which inspects the RESOLVED
 * schema (`Livewire::test(...)->assertSchemaComponentDoesNotExist(...)`)
 * rather than rendered HTML, so a field re-added here and merely hidden in a
 * Blade view would still fail the test.
 *
 * `two_factor_confirmed_at` is shown as a disabled field, never a fillable
 * one: it answers "does this user have a confirmed second factor" without
 * giving staff a way to fabricate that confirmation by hand.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Select::make('locale')
                    ->options(array_combine(
                        $locales = (array) config('magic-starter.supported_locales', ['en']),
                        $locales,
                    ))
                    ->default((string) config('magic-starter.defaults.locale', 'en'))
                    ->required(),
                DateTimePicker::make('two_factor_confirmed_at')
                    ->label('Two-factor confirmed at')
                    ->disabled()
                    ->helperText('Read-only. Reset the second factor through the product\'s own security flow, not here.'),
            ]);
    }
}
