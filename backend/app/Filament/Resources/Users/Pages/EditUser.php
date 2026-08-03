<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The header delete action is wired through
 * {@see UserResource::guardDeleteAction()}, the same guard the table row
 * action uses, so the system-team owner user cannot be deleted from either
 * surface. See {@see UserResource} for why.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::guardDeleteAction(DeleteAction::make()),
        ];
    }

    /**
     * Changing an address here invalidates its verification, exactly as the
     * product's own profile action does.
     *
     * THIS IS AN ACCESS-CONTROL FIX, NOT HOUSEKEEPING
     *
     * `UpdateUserProfile.php:112-120` nulls `email_verified_at` and re-sends the
     * verification whenever the address changes, so a verified stamp always refers
     * to the address currently on the row. Without the same rule here, a panel save
     * produced a state the product guarantees cannot exist: an address nobody
     * confirmed, carrying the verified flag through every verification-gated flow.
     *
     * The sharp edge is the panel itself. `User::canAccessPanel()` grants access on
     * exactly `email IN staff_emails AND hasVerifiedEmail() AND 2FA confirmed`, so
     * moving an allowlisted address onto some other account would have handed that
     * account the console at runtime, with no deploy and no allowlist edit. The
     * risk register accepted "every allowlist change is a deploy" on the assumption
     * that could not happen.
     *
     * No verification mail is sent from here. The staff panel is not the user's own
     * profile flow and should not send mail on somebody's behalf; nulling the stamp
     * is what re-routes them through the product's own verification path.
     *
     * Written with `forceFill()` from an AFTER-save hook rather than added to the
     * form data, and that is not a style preference. `email_verified_at` is
     * deliberately absent from `User::$fillable`, so a value injected into the
     * payload is silently dropped by mass assignment: the first version of this fix
     * did exactly that and the test caught it still holding a Carbon. Clearing a
     * verification stamp has to bypass `$fillable` on purpose, the same way the
     * system-team provisioner writes `is_system`.
     *
     * The comparison happens in the BEFORE hook and the write in the AFTER one,
     * because neither half works alone: by the time `afterSave()` runs the model has
     * re-synced its originals, so `getOriginal('email')` already returns the NEW
     * address and the comparison silently never fires. Both wrong versions were
     * tried and the test caught each.
     *
     * Pinned by `tests/Feature/Admin/UserResourceTest.php`.
     */
    protected bool $addressChanged = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        // The record still holds the stored address at this point, so this is the
        // only place the two values can honestly be compared.
        $submitted = $data['email'] ?? null;

        $this->addressChanged = is_string($submitted) && $submitted !== $this->getRecord()->email;

        return $data;
    }

    protected function afterSave(): void
    {
        if (! $this->addressChanged) {
            return;
        }

        $this->getRecord()->forceFill(['email_verified_at' => null])->save();
    }
}
