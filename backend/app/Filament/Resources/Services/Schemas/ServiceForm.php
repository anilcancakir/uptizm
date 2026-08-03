<?php

namespace App\Filament\Resources\Services\Schemas;

use App\Enums\ServiceStatusSource;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Models\Service;
use App\Support\Monitoring\HostGuard;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The catalog service form: slug/name/category, the optional provider feed
 * source and URL, the terms-review record, monitor attachment, and the
 * publish toggle.
 *
 * Two fields carry weight beyond ordinary input binding.
 *
 * `status_source_url` validates through {@see Service::assertStatusSourceUrlAllowed()}
 * rather than a bare `url()` rule, because that method already wraps
 * {@see HostGuard} and re-keys its exception onto this
 * field; re-implementing the HostGuard call here would duplicate the SSRF
 * check and risk drifting from it. `tests/Feature/Admin/ServiceResourceTest.php`
 * asserts a `127.0.0.1` URL fails validation through this field.
 *
 * `is_published` is DISABLED in the UI whenever {@see Service::canPublish()}
 * would be false for the values currently on screen, with a hint naming
 * which condition is missing. That disabled state is a UX courtesy only: a
 * disabled Livewire field is not a validation boundary, so the real
 * enforcement lives server-side in
 * {@see CreateService} and
 * {@see EditService}, which both
 * refuse a crafted `is_published => true` the same way regardless of what
 * this form rendered. `ServiceResourceTest` proves the server-side refusal
 * with a request built directly, bypassing this disabled control entirely.
 */
class ServiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('category')
                    ->required()
                    ->maxLength(255),
                Select::make('status_source')
                    ->label('Status source')
                    ->options(ServiceStatusSource::class)
                    ->required()
                    ->native(false),
                TextInput::make('status_source_url')
                    ->label('Status source URL')
                    ->maxLength(2048)
                    // Filament evaluates a `rule()` closure eagerly (with its own
                    // dependency injection) to OBTAIN the real Laravel rule, rather
                    // than passing the closure through unmodified. A bare
                    // `(string $attribute, mixed $value, Closure $fail)` closure
                    // passed directly would have its builtin-typed parameters
                    // rejected by that injection and throw a
                    // `BindingResolutionException` before validation ever runs. The
                    // outer closure below takes no parameters (so evaluation is a
                    // no-op call) and RETURNS the actual Laravel validator closure.
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        try {
                            Service::assertStatusSourceUrlAllowed($value);
                        } catch (ValidationException $exception) {
                            $fail($exception->errors()['status_source_url'][0]);
                        }
                    }),
                TextInput::make('terms_url')
                    ->label('Terms URL')
                    ->url()
                    ->maxLength(2048),
                DateTimePicker::make('terms_reviewed_at')
                    ->label('Terms reviewed at')
                    ->live()
                    ->native(false),
                Textarea::make('terms_note')
                    ->label('Terms note')
                    ->rows(3)
                    ->columnSpanFull(),
                Select::make('monitors')
                    ->relationship('monitors', 'name')
                    ->multiple()
                    ->live()
                    ->preload()
                    ->searchable()
                    ->pivotData(fn (Get $get): array => [
                        'label' => $get('monitor_label'),
                    ]),
                TextInput::make('monitor_label')
                    ->label('Monitor label')
                    ->helperText('Applied to every monitor attached above.')
                    ->maxLength(255)
                    ->dehydrated(false),
                TextInput::make('display_order')
                    ->label('Display order')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Toggle::make('is_published')
                    ->label('Published')
                    ->disabled(fn (Get $get): bool => ! self::canPublishFromFormState($get))
                    ->helperText(fn (Get $get): string => self::publishHint($get)),
            ]);
    }

    /**
     * Mirror {@see Service::canPublish()} against the CURRENT, unsaved form
     * state rather than a persisted record, so the disabled hint reacts live
     * as the operator fills in terms review and attaches a monitor. This is
     * a UI convenience only; it does not replace the server-side check the
     * two page classes run against the actual submitted data.
     */
    protected static function canPublishFromFormState(Get $get): bool
    {
        return filled($get('terms_reviewed_at')) && filled($get('monitors'));
    }

    /**
     * Name whichever publish precondition the current form state is still
     * missing, so an operator sees exactly what to fix rather than a bare
     * disabled toggle.
     */
    protected static function publishHint(Get $get): string
    {
        $missingTerms = blank($get('terms_reviewed_at'));
        $missingMonitor = blank($get('monitors'));

        if ($missingTerms && $missingMonitor) {
            return 'Cannot publish: terms have not been reviewed and no monitor is attached.';
        }

        if ($missingTerms) {
            return 'Cannot publish: terms have not been reviewed.';
        }

        if ($missingMonitor) {
            return 'Cannot publish: no monitor is attached.';
        }

        return 'This service satisfies both publish preconditions.';
    }
}
