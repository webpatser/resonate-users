# Upgrade guide

## From 1.0 to 1.1

1.1 is additive: node liveness keys and `UserRegistry::hasLiveNode()` / `liveNodes()`.

1. Restart the websocket server (`php artisan resonate:start`, or `resonate:reload`) so the plugin starts writing its node key.
2. No config change. Until every node runs 1.1, `hasLiveNode()` only sees the upgraded ones.

## From 0.7 to 1.0

1.0 is a constraint bump: there are no API, config or behaviour changes.

1. Upgrade `webpatser/resonate` to `^1.0` together with this package:

   ```bash
   composer require webpatser/resonate:^1.0 webpatser/resonate-users:^1.0
   ```

2. Nothing else changes. Existing configuration, published config files and environment variables keep working.

Resonate 0.7 is no longer supported by this release line; stay on `0.7.x` if you cannot move to resonate 1.0 yet.

## Known limitations

- Key discovery uses `SCAN`, so it is not safe on Redis Cluster. Run against a single Redis or Valkey instance (or a replicated primary) until cluster support lands.
