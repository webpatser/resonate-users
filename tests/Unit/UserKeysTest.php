<?php

use Webpatser\ResonateUsers\UserKeys;

it('names a user channel the way the pusher server library does', function () {
    // pusher/pusher-php-server's sendToUser() triggers on this exact name, so
    // the prefix is fixed by the protocol rather than by us.
    expect(UserKeys::userChannel('42'))->toBe('#server-to-user-42')
        ->and(UserKeys::isUserChannel('#server-to-user-42'))->toBeTrue()
        ->and(UserKeys::isUserChannel('presence-lobby'))->toBeFalse()
        ->and(UserKeys::isUserChannel('private-#server-to-user-42'))->toBeFalse();
});

it('scopes a key to its application, user and node', function () {
    $keys = new UserKeys('users');

    expect($keys->userKey('app-id', 'u-7', 'node-a'))->toBe('users:app-id:u-7:node-a')
        ->and($keys->userScanPattern('app-id', 'u-7'))->toBe('users:app-id:u-7:*')
        ->and($keys->appScanPattern('app-id'))->toBe('users:app-id:*');
});

it('reads the user back out of a key', function () {
    $keys = new UserKeys('users');

    expect($keys->userFromKey('app-id', 'users:app-id:u-7:node-a'))->toBe('u-7')
        // Another application's key is not ours to interpret.
        ->and($keys->userFromKey('app-id', 'users:app-two:u-7:node-a'))->toBeNull()
        ->and($keys->userFromKey('app-id', 'users:app-id:no-node-segment'))->toBeNull();
});

it('neutralises a user id that would break or widen a key', function () {
    $keys = new UserKeys('users');

    // A colon would split the key into the wrong segments, and a glob
    // metacharacter would widen a SCAN MATCH pattern onto other users' keys.
    $key = $keys->userKey('app-id', 'a:b*c', 'node-a');

    expect($key)->toBe('users:app-id:a%3Ab%2Ac:node-a')
        ->and(substr_count($key, ':'))->toBe(3)
        ->and($keys->userFromKey('app-id', $key))->toBe('a:b*c');
});

it('keeps distinct user ids distinct once encoded', function () {
    $keys = new UserKeys('users');

    // The encoding escapes "%" itself, so an id that looks like an encoded
    // colon cannot collide with one that contains a real colon.
    expect($keys->userKey('app-id', 'a%3Ab', 'node-a'))
        ->not->toBe($keys->userKey('app-id', 'a:b', 'node-a'));
});

it('gives this process a colon-free node id', function () {
    expect(UserKeys::nodeId())->not->toContain(':')
        ->and(UserKeys::nodeId())->toEndWith((string) getmypid());
});

it('falls back to the default prefix for a missing or unusable one', function () {
    expect(UserKeys::fromConfig([])->prefix())->toBe('users')
        ->and(UserKeys::fromConfig(['key_prefix' => ''])->prefix())->toBe('users')
        ->and(UserKeys::fromConfig(['key_prefix' => 'mine'])->prefix())->toBe('mine');
});
