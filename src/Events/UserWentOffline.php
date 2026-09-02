<?php

namespace Webpatser\ResonateUsers\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user's last device disconnected anywhere in the cluster.
 *
 * Fires on the node that saw the final close, once the index confirms no other
 * node still holds a device for that user. It covers a quiet death as well as a
 * clean goodbye: a socket that stops answering is pinged, pruned and closed by
 * the server, and that close reaches the plugin like any other.
 *
 * It is not a "user is gone" guarantee across a reconnect. A client that drops
 * and comes back produces an offline followed by a fresh sign-in; hold the
 * event for a grace period if you need the reconnect to pass unnoticed.
 */
class UserWentOffline
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $appId,
        public string $userId,
    ) {
        //
    }
}
