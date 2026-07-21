<?php

namespace App\Models;

use FlutterSdk\MagicStarter\Support\ConditionallyUsesUuids;
use FlutterSdk\MagicStarter\Traits\HasGuestSupport;
use FlutterSdk\MagicStarter\Traits\HasNotifications;
use FlutterSdk\MagicStarter\Traits\HasProfilePhoto;
use FlutterSdk\MagicStarter\Traits\HasTeams;
use FlutterSdk\MagicStarter\Traits\MustVerifyEmail;
use FlutterSdk\MagicStarter\Traits\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements HasLocalePreference, MustVerifyEmailContract
{
    use ConditionallyUsesUuids;
    use HasApiTokens;
    use HasFactory;
    use HasGuestSupport;
    use HasNotifications;
    use HasProfilePhoto;
    use HasTeams;
    use MustVerifyEmail;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'phone_country',
        'locale',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_guest' => 'boolean',
        ];
    }

    /**
     * Get the user's preferred locale for queued notifications and
     * mailables (see {@see HasLocalePreference}).
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
