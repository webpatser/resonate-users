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
use Webpatser\ResonateUsers\Tests\Support\FakeConnection;
use Webpatser\ResonateUsers\UserChannelPlugin;
use Webpatser\ResonateUsers\UserKeys;

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

function bootedPlugin(): UserChannelPlugin
{
    $plugin = new UserChannelPlugin;
    $plugin->boot(app(PluginContext::class));

    return $plugin;
}

function connection(string $socketId): FakeConnection
{
    return new FakeConnection($socketId, app(ApplicationProvider::class)->findById('app-id'));
}

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
