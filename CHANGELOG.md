# Changelog

All notable changes to `webpatser/resonate-users` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.7.0] - 2026-09-23

### Changed

- Versioning now tracks `webpatser/resonate`'s minor version: `0.7.x` targets resonate 0.7.
- Require `webpatser/resonate` `^0.7` (was `^0.6.2|^0.7`), dropping support for resonate 0.6 and earlier.

## [0.1.1] - 2026-09-23

### Changed

- Support `webpatser/resonate` v0.7.

## [0.1.0] - 2026-09-14

First release.

### Added

- `UserChannelPlugin`: handles the Pusher protocol's `pusher:signin`, so a connection carries a user identity whether or not it ever joins a presence channel. The signature is the one `pusher/pusher-php-server`'s `authenticateUser()` produces and Laravel's `/broadcasting/user-auth` route already serves, so an application signs its users in with machinery it already has.
- A signed-in connection joins `#server-to-user-{id}`, the channel `sendToUser()` triggers on. Delivery needs nothing new: an ordinary broadcast to that name already reaches every device on every node through the existing event dispatcher.
- `UserRegistry`: a synchronous reader for the host application. `isOnline()`, `sockets()`, `deviceCount()`, `channelFor()`, and a pipelined `snapshot()` of every connected user of an application.
- `UserDeviceIndex` and `UserKeys`: the per-node Redis index behind the reader, self-healing by TTL and rebuilt from live connections on every heartbeat.
- `UserSignedIn`, `UserSignInRejected` and `UserWentOffline` events.

### Security

- A direct `pusher:subscribe` to a user channel is refused. Those channels carry no `private-` or `presence-` prefix, so core subscribes anyone who asks; membership is granted by signin and nothing else. Without the guard one client could read another's direct messages.
- The key half of `auth` is compared against the connection's own application, so a token minted for one tenant cannot be presented on another tenant's socket.
- A second signin on an identified connection is refused rather than allowed to switch a live socket's identity.
- User ids are percent-encoded before they form a Redis key, so a colon cannot split a key into the wrong segments and a glob metacharacter cannot widen a `SCAN` sweep onto another user.

### Fixed

- `POST /apps/{id}/users/{userId}/terminate_connections` reaches signed-in users who never joined a presence channel, on this node and across the cluster. Both paths match on the `user_id` recorded against a channel connection, and the user channel subscription carries it. No change to the server was needed.
