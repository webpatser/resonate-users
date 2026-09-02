<?php

use Predis\Client;
use Pusher\Pusher;
use Revolt\EventLoop;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Contracts\ChannelManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelConnectionManager;
use Webpatser\Resonate\Protocols\Pusher\Managers\ArrayChannelManager;
use Webpatser\ResonateUsers\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Integration');

/*
 * The Pusher channel managers are bound per test so the plugin and the
 * PluginContext exercise a real, isolated channel registry.
 */
uses()->beforeEach(function () {
    $this->app->singleton(ChannelManager::class, fn () => new ArrayChannelManager);
    $this->app->bind(ChannelConnectionManager::class, fn () => new ArrayChannelConnectionManager);
})->in(__DIR__.'/Integration');

/**
 * Determine whether a Redis server is reachable for the integration tests.
 */
function redisReachable(): bool
{
    $connection = @fsockopen('127.0.0.1', 6379, $errno, $errstr, 0.5);

    if ($connection === false) {
        return false;
    }

    fclose($connection);

    return true;
}

/**
 * Build a signin payload the way a real Pusher client library would.
 *
 * This goes through `pusher/pusher-php-server` itself rather than rebuilding
 * the signature by hand, so the plugin is verified against the exact bytes
 * Laravel's `/broadcasting/user-auth` route serves, not against our reading of
 * the specification.
 *
 * @param  array<string, mixed>  $userData
 * @return array{auth: string, user_data: string}
 */
function signinPayload(string $socketId, array $userData, string $key = 'app-key', string $secret = 'app-secret'): array
{
    $pusher = new Pusher($key, $secret, 'app-id', ['host' => 'localhost', 'useTLS' => false]);

    /** @var array{auth: string, user_data: string} $decoded */
    $decoded = json_decode($pusher->authenticateUser($socketId, $userData), associative: true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * Remove every key this package's tests write.
 */
function flushUserKeys(Client $redis): void
{
    foreach ($redis->keys('users-test:*') as $key) {
        $redis->del($key);
    }
}

/**
 * Run a closure inside the Revolt event loop, surfacing any failure.
 */
function runLoop(Closure $body): void
{
    $error = null;

    $watchdog = EventLoop::delay(5.0, function () use (&$error) {
        $error = 'event loop timed out';
        EventLoop::getDriver()->stop();
    });

    EventLoop::queue(function () use ($body, &$error, $watchdog) {
        try {
            $body();
        } catch (Throwable $e) {
            $error = $e->getMessage()."\n".$e->getTraceAsString();
        } finally {
            EventLoop::cancel($watchdog);
            EventLoop::getDriver()->stop();
        }
    });

    EventLoop::run();

    if ($error !== null) {
        throw new RuntimeException($error);
    }
}
