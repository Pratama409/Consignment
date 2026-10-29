<?php

namespace App\Providers;

use App\Infrastructure\UuidDatabaseQueue;
use App\Infrastructure\UuidDatabaseSessionHandler;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app['session']->extend('database', fn ($app) => new UuidDatabaseSessionHandler(
            $app['db']->connection(config('session.connection')), config('session.table'), config('session.lifetime'), $app
        ));
        $this->app['queue']->addConnector('database', fn () => new class($this->app) implements ConnectorInterface
        {
            public function __construct(private $app) {}

            public function connect(array $config)
            {
                return new UuidDatabaseQueue($this->app['db']->connection($config['connection'] ?? null), $config['table'], $config['queue'], $config['retry_after'] ?? 60, $config['after_commit'] ?? false);
            }
        });
    }
}
