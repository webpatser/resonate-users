<?php

namespace Webpatser\ResonateUsers\Tests\Support;

use Fledge\Async\Redis\Connection\ReconnectingRedisLink;
use Fledge\Async\Redis\RedisClient;
use Fledge\Async\Redis\RedisConfig;
use Webpatser\ResonateUsers\UserChannelPlugin;

use function Fledge\Async\Redis\createRedisConnector;

/**
 * The real plugin, writing to the real Redis through a {@see CountingRedisLink}.
 */
class CountingUserChannelPlugin extends UserChannelPlugin
{
    /**
     * The link every index command passes through.
     */
    public ?CountingRedisLink $link = null;

    protected function makeClient(RedisConfig $config): RedisClient
    {
        $this->link = new CountingRedisLink(new ReconnectingRedisLink(createRedisConnector($config)));

        return new RedisClient($this->link);
    }
}
