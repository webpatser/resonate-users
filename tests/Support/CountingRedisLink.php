<?php

namespace Webpatser\ResonateUsers\Tests\Support;

use Closure;
use Fledge\Async\Redis\Connection\RedisLink;
use Fledge\Async\Redis\Protocol\RedisError;
use Fledge\Async\Redis\Protocol\RedisResponse;

/**
 * A counting wrapper around a real Redis link.
 *
 * Every command still reaches the real server; the wrapper only records the
 * command names and how many were awaiting a reply at once. A count above one
 * means the commands went out pipelined on the connection rather than one
 * round trip after another.
 */
class CountingRedisLink implements RedisLink
{
    /**
     * Every command sent, lowercased, in the order sent.
     *
     * @var list<string>
     */
    public array $commands = [];

    /**
     * The most commands that were awaiting a reply at the same time.
     */
    public int $maxInFlight = 0;

    /**
     * The commands awaiting a reply right now.
     */
    protected int $inFlight = 0;

    public function __construct(protected RedisLink $inner)
    {
        //
    }

    /**
     * When set, the next EVALSHA runs this first and is answered NOSCRIPT
     * without reaching the server, as after a restart or SCRIPT FLUSH.
     */
    public ?Closure $noScriptOnce = null;

    /**
     * Closures run once, by lowercased command name, while that command's
     * caller is suspended in execute() and before the command is sent.
     *
     * @var array<string, Closure>
     */
    public array $beforeOnce = [];

    public function execute(string $command, array $parameters): RedisResponse
    {
        $this->commands[] = strtolower($command);
        $this->maxInFlight = max($this->maxInFlight, ++$this->inFlight);

        try {
            if (isset($this->beforeOnce[strtolower($command)])) {
                $before = $this->beforeOnce[strtolower($command)];
                unset($this->beforeOnce[strtolower($command)]);

                $before();
            }

            if ($this->noScriptOnce !== null && strcasecmp($command, 'evalsha') === 0) {
                $before = $this->noScriptOnce;
                $this->noScriptOnce = null;

                $before();

                return new RedisError('NOSCRIPT No matching script. Please use EVAL.');
            }

            return $this->inner->execute($command, $parameters);
        } finally {
            $this->inFlight--;
        }
    }

    /**
     * Forget what has been recorded so far.
     */
    public function reset(): void
    {
        $this->commands = [];
        $this->maxInFlight = 0;
    }

    /**
     * How many times a command was sent.
     */
    public function count(string $command): int
    {
        return count(array_keys($this->commands, strtolower($command), true));
    }
}
