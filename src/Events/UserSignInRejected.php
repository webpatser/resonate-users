<?php

namespace Webpatser\ResonateUsers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A signin attempt was refused.
 *
 * The reason is one of the constants below rather than a message, so a listener
 * can alert on a bad signature without matching on prose. A run of
 * INVALID_SIGNATURE from one address is worth watching.
 */
class UserSignInRejected
{
    use Dispatchable;

    /** The payload was not a signin frame this plugin could read. */
    public const string MALFORMED = 'malformed';

    /** The HMAC did not verify, or named another application's key. */
    public const string INVALID_SIGNATURE = 'invalid_signature';

    /** The signed blob carried no usable `id` field. */
    public const string MISSING_USER_ID = 'missing_user_id';

    /** The connection had already signed in as someone. */
    public const string ALREADY_SIGNED_IN = 'already_signed_in';

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $reason,
        public string $appId,
        public string $socketId,
    ) {
        //
    }
}
