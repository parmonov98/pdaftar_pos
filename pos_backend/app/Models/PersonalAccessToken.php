<?php

declare(strict_types=1);

namespace Pos\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * The POS's token model.
 *
 * Same shape as pDaftar's — the device columns are kept because
 * PosTerminalService records which machine a token belongs to, and a token
 * whose device is unknown is one support cannot trace back to a till.
 *
 * It is a copy rather than a reuse because this is the one class that must not
 * come from the sibling checkout: Sanctum resolves EVERY authenticated request
 * through it, so pointing at pDaftar's version would make the POS unable to
 * authenticate anyone the moment ../../backend is absent.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken {
    /** @var array<int, string> */
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'device_name',
        'device_type',
        'platform',
        'user_agent',
        'ip_address',
    ];
}
