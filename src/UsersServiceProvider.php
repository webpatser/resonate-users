<?php

namespace Webpatser\ResonateUsers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Webpatser\Resonate\Contracts\ApplicationProvider;

/**
 * Wires the users plugin into a host Laravel application.
 *
 * The {@see UserChannelPlugin} itself is not bound here: Resonate instantiates
 * it from the `plugins` array in `config/reverb.php`. What is bound is the
 * read side, so application code can inject {@see UserRegistry} and ask who is
 * connected.
 */
class UsersServiceProvider extends ServiceProvider
{
    /**
     * Register the package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/resonate-users.php', 'resonate-users');

        $this->app->singleton(UserRegistry::class, function (Application $app): UserRegistry {
            return new UserRegistry(
                (array) $app->make('config')->get('resonate-users', []),
                $app->bound(ApplicationProvider::class) ? $app->make(ApplicationProvider::class) : null,
            );
        });
    }

    /**
     * Bootstrap the package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/resonate-users.php' => $this->app->configPath('resonate-users.php'),
            ], 'resonate-users-config');
        }
    }
}
