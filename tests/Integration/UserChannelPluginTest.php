<?php

use Illuminate\Support\Facades\Event;
use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Plugins\MessageDisposition;
use Webpatser\Resonate\Plugins\PluginContext;
use Webpatser\Resonate\Plugins\PluginManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\EventDispatcher;
use Webpatser\ResonateUsers\Events\UserSignedIn;
use Webpatser\ResonateUsers\Events\UserSignInRejected;
use Webpatser\ResonateUsers\Events\UserWentOffline;
use Webpatser\ResonateUsers\Tests\Support\CountingUserChannelPlugin;
use Webpatser\ResonateUsers\Tests\Support\FakeConnection;
use Webpatser\ResonateUsers\UserChannelPlugin;
use Webpatser\ResonateUsers\UserKeys;
use Webpatser\ResonateUsers\UserRegistry;

beforeEach(function () {
    if (! redisReachable()) {
        $this->markTestSkipped('Redis not reachable');
    }

    $this->redis = new Client(['host' => '127.0.0.1', 'port' => 6379, 'database' => 15]);

    flushUserKeys($this->redis);

    $this->application = app(ApplicationProvider::class)->findById('app-id');
});

afterEach(function () {
    if (isset($this->redis)) {
        flushUserKeys($this->redis);
    }
});

/**
 * The decoded frames a fake connection was sent.
 *
 * @return list<array<string, mixed>>
 */
function frames(FakeConnection $connection): array
{
    return array_map(
        fn (string $message): array => (array) json_decode($message, associative: true),
        $connection->messages,
    );
}

function frameNames(FakeConnection $connection): array
{
    return array_map(fn (array $frame): string => (string) ($frame['event'] ?? ''), frames($connection));
}

it('signs a connection in and joins it to its own channel', function () {
    Event::fake([UserSignedIn::class]);

    $connection = connection('1001.1');
    $payload = signinPayload('1001.1', ['id' => '42', 'user_info' => ['name' => 'Ada']]);

    $disposition = null;

    runLoop(function () use (&$disposition, $connection, $payload) {
        $disposition = bootedPlugin()->onMessage($connection, [
            'event' => 'pusher:signin',
            'data' => $payload,
        ]);
    });

    $channel = app(ChannelManager::class)->for($this->application)->find('#server-to-user-42');

    expect($disposition)->toBe(MessageDisposition::Handled)
        ->and($connection->state(UserChannelPlugin::USER_ID))->toBe('42')
        ->and($connection->state(UserChannelPlugin::USER_INFO))->toBe(['name' => 'Ada'])
        ->and(frameNames($connection))->toContain('pusher:signin_success')
        ->and($channel?->findById('1001.1'))->not->toBeNull();

    Event::assertDispatched(UserSignedIn::class);
});

it('records the signed-in socket in the cluster index', function () {
    $connection = connection('1001.1');
    $payload = signinPayload('1001.1', ['id' => '42']);

    runLoop(function () use ($connection, $payload) {
        bootedPlugin()->onMessage($connection, ['event' => 'pusher:signin', 'data' => $payload]);
    });

    $keys = $this->redis->keys('users-test:app-id:42:*');

    expect($keys)->toHaveCount(1)
        ->and($this->redis->smembers($keys[0]))->toBe(['1001.1']);
});

it('refuses a signature the application did not produce', function () {
    Event::fake([UserSignInRejected::class]);

    $connection = connection('1001.1');
    $payload = signinPayload('1001.1', ['id' => '42']);
    $payload['auth'] = 'app-key:'.str_repeat('0', 64);

    $disposition = null;

    runLoop(function () use (&$disposition, $connection, $payload) {
        $disposition = bootedPlugin()->onMessage($connection, ['event' => 'pusher:signin', 'data' => $payload]);
    });

    expect($disposition)->toBe(MessageDisposition::Rejected)
        ->and($connection->hasState(UserChannelPlugin::USER_ID))->toBeFalse()
        ->and(frameNames($connection))->toBe(['pusher:error']);

    Event::assertDispatched(
        UserSignInRejected::class,
        fn (UserSignInRejected $event): bool => $event->reason === UserSignInRejected::INVALID_SIGNATURE,
    );
});

it('refuses a signature minted for another application', function () {
    // The blob is signed correctly, just with a different tenant's secret. The
    // key half of `auth` names that other application, so this is caught before
    // the HMAC is even compared.
    $connection = connection('1001.1');
    $payload = signinPayload('1001.1', ['id' => '42'], key: 'other-key', secret: 'other-secret');

    $disposition = null;

    runLoop(function () use (&$disposition, $connection, $payload) {
        $disposition = bootedPlugin()->onMessage($connection, ['event' => 'pusher:signin', 'data' => $payload]);
    });

    expect($disposition)->toBe(MessageDisposition::Rejected)
        ->and($connection->hasState(UserChannelPlugin::USER_ID))->toBeFalse();
});

it('refuses a blob signed for a different socket', function () {
    // The signature covers the socket id, so a payload lifted from another
    // connection cannot be replayed onto this one.
    $connection = connection('1001.1');
    $payload = signinPayload('1002.2', ['id' => '42']);

    $disposition = null;

    runLoop(function () use (&$disposition, $connection, $payload) {
        $disposition = bootedPlugin()->onMessage($connection, ['event' => 'pusher:signin', 'data' => $payload]);
    });

    expect($disposition)->toBe(MessageDisposition::Rejected);
});

it('refuses a signed blob that names no user', function () {
    Event::fake([UserSignInRejected::class]);

    // Signed correctly, but with no `id`. pusher-php-server would not mint this
    // one, so it is signed by hand.
    $connection = connection('1001.1');
    $userData = json_encode(['user_info' => ['name' => 'Ada']], JSON_THROW_ON_ERROR);
    $payload = [
        'auth' => 'app-key:'.hash_hmac('sha256', '1001.1::user::'.$userData, 'app-secret'),
        'user_data' => $userData,
    ];

    $disposition = null;

    runLoop(function () use (&$disposition, $connection, $payload) {
        $disposition = bootedPlugin()->onMessage($connection, ['event' => 'pusher:signin', 'data' => $payload]);
    });

    expect($disposition)->toBe(MessageDisposition::Rejected);

    Event::assertDispatched(
        UserSignInRejected::class,
        fn (UserSignInRejected $event): bool => $event->reason === UserSignInRejected::MISSING_USER_ID,
    );
});

it('refuses a second signin rather than letting a socket change identity', function () {
    $connection = connection('1001.1');
    $first = signinPayload('1001.1', ['id' => '42']);
    $second = signinPayload('1001.1', ['id' => '99']);

    $dispositions = [];

    runLoop(function () use (&$dispositions, $connection, $first, $second) {
        $plugin = bootedPlugin();
        $dispositions[] = $plugin->onMessage($connection, ['event' => 'pusher:signin', 'data' => $first]);
        $dispositions[] = $plugin->onMessage($connection, ['event' => 'pusher:signin', 'data' => $second]);
    });

    expect($dispositions)->toBe([MessageDisposition::Handled, MessageDisposition::Rejected])
        ->and($connection->state(UserChannelPlugin::USER_ID))->toBe('42');
});

it('refuses a client that tries to subscribe to a user channel directly', function () {
    // A user channel carries no private- or presence- prefix, so core would
    // subscribe anyone who asked. Membership comes from signin and nothing else;
    // without this guard one client could read another's direct messages.
    $intruder = connection('2002.2');

    $disposition = null;

    runLoop(function () use (&$disposition, $intruder) {
        $disposition = bootedPlugin()->onMessage($intruder, [
            'event' => 'pusher:subscribe',
            'data' => ['channel' => '#server-to-user-42'],
        ]);
    });

    $channel = app(ChannelManager::class)->for($this->application)->find('#server-to-user-42');

    expect($disposition)->toBe(MessageDisposition::Rejected)
        ->and($channel)->toBeNull()
        ->and(frameNames($intruder))->toBe(['pusher:error']);
});

it('does not deliver a direct message to a client that never signed in as that user', function () {
    $owner = connection('2001.1');
    $intruder = connection('2002.2');

    runLoop(function () use ($owner, $intruder) {
        $plugin = bootedPlugin();

        $plugin->onMessage($owner, [
            'event' => 'pusher:signin',
            'data' => signinPayload('2001.1', ['id' => '42']),
        ]);

        // The intruder tries the channel directly, then a message is sent.
        $plugin->onMessage($intruder, [
            'event' => 'pusher:subscribe',
            'data' => ['channel' => '#server-to-user-42'],
        ]);
    });

    $owner->messages = [];
    $intruder->messages = [];

    EventDispatcher::dispatch($this->application, [
        'event' => 'chat.dm',
        'channel' => UserKeys::userChannel('42'),
        'data' => ['body' => 'for your eyes only'],
    ]);

    expect($owner->messages)->toHaveCount(1)
        ->and($owner->messages[0])->toContain('for your eyes only')
        ->and($intruder->messages)->toBe([]);
});

it('reaches every device a user has signed in on', function () {
    $laptop = connection('3001.1');
    $phone = connection('3002.2');

    runLoop(function () use ($laptop, $phone) {
        $plugin = bootedPlugin();

        foreach ([$laptop, $phone] as $device) {
            $plugin->onMessage($device, [
                'event' => 'pusher:signin',
                'data' => signinPayload($device->id(), ['id' => '42']),
            ]);
        }
    });

    $laptop->messages = [];
    $phone->messages = [];

    EventDispatcher::dispatch($this->application, [
        'event' => 'chat.dm',
        'channel' => UserKeys::userChannel('42'),
        'data' => ['body' => 'hello'],
    ]);

    expect($laptop->messages)->toHaveCount(1)
        ->and($phone->messages)->toHaveCount(1);
});

it('relays every event it does not own', function () {
    $connection = connection('1001.1');

    $dispositions = [];

    runLoop(function () use (&$dispositions, $connection) {
        $plugin = bootedPlugin();

        $dispositions[] = $plugin->onMessage($connection, [
            'event' => 'pusher:subscribe',
            'data' => ['channel' => 'presence-lobby'],
        ]);
        $dispositions[] = $plugin->onMessage($connection, ['event' => 'client-typing', 'channel' => 'private-chat']);
        $dispositions[] = $plugin->onMessage($connection, ['event' => 'pusher:ping']);
    });

    expect($dispositions)->each->toBe(MessageDisposition::Relay)
        ->and($connection->messages)->toBe([]);
});

it('drops a closing connection from the index and reports the user offline', function () {
    Event::fake([UserWentOffline::class]);

    $connection = connection('1001.1');

    runLoop(function () use ($connection) {
        $plugin = bootedPlugin();

        $plugin->onMessage($connection, [
            'event' => 'pusher:signin',
            'data' => signinPayload('1001.1', ['id' => '42']),
        ]);

        $plugin->onClose($connection);
    });

    expect($this->redis->keys('users-test:app-id:42:*'))->toBe([])
        ->and($connection->hasState(UserChannelPlugin::USER_ID))->toBeFalse();

    Event::assertDispatched(UserWentOffline::class);
});

it('does not report a user offline while another device is still connected', function () {
    Event::fake([UserWentOffline::class]);

    $laptop = connection('3001.1');
    $phone = connection('3002.2');

    runLoop(function () use ($laptop, $phone) {
        $plugin = bootedPlugin();

        foreach ([$laptop, $phone] as $device) {
            $plugin->onMessage($device, [
                'event' => 'pusher:signin',
                'data' => signinPayload($device->id(), ['id' => '42']),
            ]);
        }

        $plugin->onClose($laptop);
    });

    Event::assertNotDispatched(UserWentOffline::class);
});

it('rebuilds the index from live connections on the heartbeat', function () {
    $connection = connection('1001.1');

    runLoop(function () use ($connection) {
        $plugin = bootedPlugin();

        $plugin->onMessage($connection, [
            'event' => 'pusher:signin',
            'data' => signinPayload('1001.1', ['id' => '42']),
        ]);

        $key = $this->redis->keys('users-test:app-id:42:*')[0];

        // A ghost a missed close left behind. The heartbeat is authoritative,
        // so it must go; otherwise its TTL is refreshed forever and the person
        // reads as connected on a device that does not exist.
        $this->redis->sadd($key, ['9999.9']);

        foreach ($plugin->ticks() as $tick) {
            ($tick['callback'])();
        }
    });

    $key = $this->redis->keys('users-test:app-id:42:*')[0];

    expect($this->redis->smembers($key))->toBe(['1001.1']);
});

it('does not sweep the keyspace when a user still has a device on this node', function () {
    Event::fake([UserWentOffline::class]);

    $laptop = connection('3001.1');
    $phone = connection('3002.2');
    $plugin = new CountingUserChannelPlugin;

    runLoop(function () use ($plugin, $laptop, $phone) {
        $plugin->boot(app(PluginContext::class));

        foreach ([$laptop, $phone] as $device) {
            $plugin->onMessage($device, [
                'event' => 'pusher:signin',
                'data' => signinPayload($device->id(), ['id' => '42']),
            ]);
        }

        $plugin->link->reset();

        $plugin->onClose($laptop);
    });

    // The phone is still signed in here, so the user is online without asking
    // Redis. The removal itself is the only command the close needs.
    expect($plugin->link->commands)->toBe(['srem'])
        ->and($this->redis->smembers($this->redis->keys('users-test:app-id:42:*')[0]))->toBe(['3002.2']);

    Event::assertNotDispatched(UserWentOffline::class);
});

it('still asks the cluster when the last local device of a user closes', function () {
    Event::fake([UserWentOffline::class]);

    $connection = connection('1001.1');
    $plugin = new CountingUserChannelPlugin;

    runLoop(function () use ($plugin, $connection) {
        $plugin->boot(app(PluginContext::class));

        $plugin->onMessage($connection, [
            'event' => 'pusher:signin',
            'data' => signinPayload('1001.1', ['id' => '42']),
        ]);

        $plugin->link->reset();

        $plugin->onClose($connection);
    });

    expect($plugin->link->count('scan'))->toBeGreaterThanOrEqual(1);

    Event::assertDispatched(UserWentOffline::class);
});

it('rewrites every tracked user in one pipelined command each on the heartbeat', function () {
    $users = ['41' => '4001.1', '42' => '4002.2', '43' => '4003.3'];
    $plugin = new CountingUserChannelPlugin;

    runLoop(function () use ($plugin, $users) {
        $plugin->boot(app(PluginContext::class));

        foreach ($users as $userId => $socketId) {
            $plugin->onMessage(connection($socketId), [
                'event' => 'pusher:signin',
                'data' => signinPayload($socketId, ['id' => (string) $userId]),
            ]);
        }

        // A ghost on one user, so the rewrite has something to drop.
        $this->redis->sadd($this->redis->keys('users-test:app-id:42:*')[0], ['9999.9']);

        // The first tick may load the script; the second is the steady state.
        foreach ($plugin->ticks() as $tick) {
            ($tick['callback'])();
        }

        $plugin->link->reset();

        foreach ($plugin->ticks() as $tick) {
            ($tick['callback'])();
        }
    });

    // One liveness SET, then a single script call per user, all in flight at
    // once: no SMEMBERS read, no separate SREM, SADD or EXPIRE round trips.
    expect($plugin->link->commands)->toBe(['set', 'evalsha', 'evalsha', 'evalsha'])
        ->and($plugin->link->maxInFlight)->toBe(3);

    foreach ($users as $userId => $socketId) {
        $key = $this->redis->keys("users-test:app-id:{$userId}:*")[0];

        expect($this->redis->smembers($key))->toBe([$socketId])
            ->and($this->redis->ttl($key))->toBeGreaterThan(0)->toBeLessThanOrEqual(90);
    }
});

it('rewrites a large index in bounded chunks on the heartbeat', function () {
    $plugin = new CountingUserChannelPlugin;

    runLoop(function () use ($plugin) {
        $plugin->boot(app(PluginContext::class));

        for ($user = 1; $user <= 1200; $user++) {
            $socketId = "{$user}.1";

            $plugin->onMessage(connection($socketId), [
                'event' => 'pusher:signin',
                'data' => signinPayload($socketId, ['id' => (string) $user]),
            ]);
        }

        // Ghosts on the first and the last user, one in each outer chunk.
        foreach (['1', '1200'] as $user) {
            $this->redis->sadd($this->redis->keys("users-test:app-id:{$user}:*")[0], ['9999.9']);
        }

        // Warm the script cache, then measure a steady tick.
        foreach ($plugin->ticks() as $tick) {
            ($tick['callback'])();
        }

        $plugin->link->reset();

        foreach ($plugin->ticks() as $tick) {
            ($tick['callback'])();
        }
    }, timeout: 30.0);

    // Every in-flight command is one user's fiber waiting on its reply, so this
    // is the number of rewrite fibers alive at once: capped, yet pipelined.
    expect($plugin->link->count('evalsha'))->toBe(1200)
        ->and($plugin->link->maxInFlight)->toBe(500)
        ->and($this->redis->keys('users-test:app-id:*'))->toHaveCount(1200)
        ->and($this->redis->smembers($this->redis->keys('users-test:app-id:1:*')[0]))->toBe(['1.1'])
        ->and($this->redis->smembers($this->redis->keys('users-test:app-id:1200:*')[0]))->toBe(['1200.1']);
});

it('keeps a device that signed in while the rewrite script was being reloaded', function () {
    $laptop = connection('5001.1');
    $phone = connection('5002.2');
    $plugin = new CountingUserChannelPlugin;

    runLoop(function () use ($plugin, $laptop, $phone) {
        $plugin->boot(app(PluginContext::class));

        $plugin->onMessage($laptop, [
            'event' => 'pusher:signin',
            'data' => signinPayload('5001.1', ['id' => '42']),
        ]);

        // The script cache is gone (restart, failover, SCRIPT FLUSH). The phone
        // signs in after the EVALSHA went out and before its NOSCRIPT reply.
        $plugin->link->noScriptOnce = function () use ($plugin, $phone) {
            $plugin->onMessage($phone, [
                'event' => 'pusher:signin',
                'data' => signinPayload('5002.2', ['id' => '42']),
            ]);
        };

        foreach ($plugin->ticks() as $tick) {
            ($tick['callback'])();
        }
    });

    $members = $this->redis->smembers($this->redis->keys('users-test:app-id:42:*')[0]);
    sort($members);

    expect($members)->toBe(['5001.1', '5002.2'])
        ->and($plugin->link->noScriptOnce)->toBeNull();
});

it('leaves nothing behind for a socket that closed while its signin was being recorded', function () {
    $connection = connection('6001.1');
    $plugin = new CountingUserChannelPlugin;

    runLoop(function () use ($plugin, $connection) {
        $plugin->boot(app(PluginContext::class));

        // The socket closes while the signin is suspended in index->add, in
        // the worst order: its close runs before the SADD reaches Redis.
        $plugin->link->beforeOnce['sadd'] = fn () => $plugin->onClose($connection);

        $plugin->onMessage($connection, [
            'event' => 'pusher:signin',
            'data' => signinPayload('6001.1', ['id' => '42']),
        ]);
    });

    $channel = app(ChannelManager::class)->for($this->application)->find('#server-to-user-42');

    expect($plugin->link->beforeOnce)->toBe([])
        ->and($channel?->findById('6001.1'))->toBeNull()
        ->and($this->redis->keys('users-test:app-id:42:*'))->toBe([])
        ->and(frameNames($connection))->not->toContain('pusher:signin_success')
        ->and($connection->hasState(UserChannelPlugin::USER_ID))->toBeFalse();
});

it('marks the node alive at boot with nobody signed in', function () {
    runLoop(function () {
        bootedPlugin();
    });

    $key = 'users-test-nodes:app-id:'.UserKeys::nodeId();

    expect($this->redis->exists($key))->toBe(1)
        ->and($this->redis->ttl($key))->toBeGreaterThan(0)->toBeLessThanOrEqual(90)
        ->and($this->redis->keys('users-test:*'))->toBe([])
        ->and(app(UserRegistry::class)->liveNodes())->toBe([UserKeys::nodeId()]);
});

it('refreshes the node key on every heartbeat whether or not anyone is tracked', function () {
    $key = 'users-test-nodes:app-id:'.UserKeys::nodeId();

    runLoop(function () use ($key) {
        $plugin = bootedPlugin();

        // As if the key had lapsed, say after a Redis restart. The next tick
        // must put it back even though this node tracks no user at all.
        $this->redis->del($key);

        foreach ($plugin->ticks() as $tick) {
            ($tick['callback'])();
        }
    });

    expect($this->redis->exists($key))->toBe(1)
        ->and($this->redis->ttl($key))->toBeGreaterThan(0);
});

it('works when registered the way the server registers it', function () {
    // The tests above drive the hooks directly. This one goes through the real
    // PluginManager, so container resolution, capability indexing and the
    // interceptor chain are exercised as the running server exercises them.
    $manager = new PluginManager(app(PluginContext::class));
    $manager->register(app(UserChannelPlugin::class));

    $connection = connection('1001.1');

    $disposition = null;

    runLoop(function () use (&$disposition, $manager, $connection) {
        $manager->boot();

        $disposition = $manager->interceptMessage($connection, [
            'event' => 'pusher:signin',
            'data' => signinPayload('1001.1', ['id' => '42']),
        ]);
    });

    $channel = app(ChannelManager::class)->for($this->application)->find('#server-to-user-42');

    expect($manager->hasPlugins())->toBeTrue()
        ->and($disposition)->toBe(MessageDisposition::Handled)
        ->and($channel?->findById('1001.1'))->not->toBeNull()
        ->and($manager->ticks())->toHaveCount(1);

    // And the guard still holds through the chain.
    runLoop(function () use ($manager) {
        $intruder = connection('2002.2');

        expect($manager->interceptMessage($intruder, [
            'event' => 'pusher:subscribe',
            'data' => ['channel' => '#server-to-user-42'],
        ]))->toBe(MessageDisposition::Rejected);
    });
});
