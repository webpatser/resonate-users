<?php

namespace Webpatser\ResonateUsers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A connection proved a user identity and joined that user's channel.
 *
 * Fires once per connection, not once per user: a person on three devices
 * produces three of these.
 */
class UserSignedIn
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $appId,
        public string $userId,
        public string $socketId,
    ) {
        //
    }
}
