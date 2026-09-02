<?php

use Predis\Client;
use Webpatser\Resonate\Contracts\ApplicationProvider;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;

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
 * Terminate a user's connections the way UsersTerminateController does.
 *
 * The controller walks the flattened channel connections and disconnects any
 * whose recorded `user_id` matches. Driving that loop directly keeps this test
 * on the plugin rather than on the HTTP layer, while exercising the exact
 * lookup the controller and the cross-node terminate envelope both perform.
 */
function terminateUser(string $userId): void
{
    foreach (app(ChannelManager::class)->for(app(ApplicationProvider::class)->findById('app-id'))->connections() as $connection) {
        if ((string) $connection->data('user_id') === $userId) {
            $connection->disconnect();
        }
    }
}

it('terminates a signed-in user who never joined a presence channel', function () {
    // This is the behaviour that does not exist without the plugin. A
    // connection only gains a user_id by joining a presence channel, so a
    // client that just signed in and waits for direct messages is invisible to
    // terminate_connections. Signing in subscribes it to its own channel with
    // channel_data naming the user, which is exactly what the lookup matches.
    $connection = connection('1001.1');

    runLoop(function () use ($connection) {
        bootedPlugin()->onMessage($connection, [
            'event' => 'pusher:signin',
            'data' => signinPayload('1001.1', ['id' => '42']),
        ]);
    });

    expect($connection->terminated)->toBeFalse();

    terminateUser('42');

    expect($connection->terminated)->toBeTrue();
});

it('terminates every device of the user and no one else', function () {
    $laptop = connection('3001.1');
    $phone = connection('3002.2');
    $someoneElse = connection('2001.1');

    runLoop(function () use ($laptop, $phone, $someoneElse) {
        $plugin = bootedPlugin();

        foreach ([$laptop, $phone] as $device) {
            $plugin->onMessage($device, [
                'event' => 'pusher:signin',
                'data' => signinPayload($device->id(), ['id' => '42']),
            ]);
        }

        $plugin->onMessage($someoneElse, [
            'event' => 'pusher:signin',
            'data' => signinPayload('2001.1', ['id' => '99']),
        ]);
    });

    terminateUser('42');

    expect($laptop->terminated)->toBeTrue()
        ->and($phone->terminated)->toBeTrue()
        ->and($someoneElse->terminated)->toBeFalse();
});
