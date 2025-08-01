<?php

declare(strict_types=1);

namespace Tempest\Core;

use Dotenv\Dotenv;
use Tempest\Container\Container;
use Tempest\Container\GenericContainer;
use Tempest\Core\Kernel\FinishDeferredTasks;
use Tempest\Core\Kernel\LoadConfig;
use Tempest\Core\Kernel\LoadDiscoveryClasses;
use Tempest\Core\Kernel\LoadDiscoveryLocations;
use Tempest\Core\Kernel\RegisterEmergencyExceptionHandler;
use Tempest\Core\ShellExecutors\GenericShellExecutor;
use Tempest\EventBus\EventBus;

final class FrameworkKernel implements Kernel
{
    public readonly Container $container;

    public bool $discoveryCache;

    public array $discoveryClasses = [];

    public string $internalStorage;

    public function __construct(
        public string $root,
        /** @var \Tempest\Discovery\DiscoveryLocation[] $discoveryLocations */
        public array $discoveryLocations = [],
        ?Container $container = null,
    ) {
        $this->container = $container ?? $this->createContainer();
    }

    public static function boot(
        string $root,
        array $discoveryLocations = [],
        ?Container $container = null,
    ): self {
        if (! defined('TEMPEST_START')) {
            define('TEMPEST_START', value: hrtime(true));
        }

        return new self(
            root: $root,
            discoveryLocations: $discoveryLocations,
            container: $container,
        )
            ->validateRoot()
            ->loadEnv()
            ->registerEmergencyExceptionHandler()
            ->registerShutdownFunction()
            ->registerInternalStorage()
            ->registerKernel()
            ->loadComposer()
            ->loadDiscoveryLocations()
            ->loadConfig()
            ->loadDiscovery()
            ->registerExceptionHandler()
            ->event(KernelEvent::BOOTED);
    }

    public function validateRoot(): self
    {
        $root = realpath($this->root);

        if (! is_dir($root)) {
            throw new \RuntimeException('The specified root directory is not valid.');
        }

        $this->root = $root;

        return $this;
    }

    public function shutdown(int|string $status = ''): never
    {
        $this->finishDeferredTasks()
            ->event(KernelEvent::SHUTDOWN);

        exit($status);
    }

    public function createContainer(): Container
    {
        $container = new GenericContainer();

        GenericContainer::setInstance($container);

        $container->singleton(Container::class, fn () => $container);

        return $container;
    }

    public function loadComposer(): self
    {
        $composer = new Composer(
            root: $this->root,
            executor: new GenericShellExecutor(),
        )->load();

        $this->container->singleton(Composer::class, $composer);

        return $this;
    }

    public function loadEnv(): self
    {
        $dotenv = Dotenv::createUnsafeImmutable($this->root);
        $dotenv->safeLoad();

        return $this;
    }

    public function registerKernel(): self
    {
        $this->container->singleton(Kernel::class, $this);
        $this->container->singleton(self::class, $this);

        return $this;
    }

    public function registerShutdownFunction(): self
    {
        // Fix for classes that don't have a proper PSR-4 namespace,
        // they break discovery with an unrecoverable error,
        // but you don't know why because PHP simply says "duplicate classname" instead of something reasonable.
        register_shutdown_function(function (): void {
            $error = error_get_last();

            $message = $error['message'] ?? '';

            if (str_contains($message, 'Cannot declare class')) {
                echo 'Does this class have the right namespace?' . PHP_EOL;
            }
        });

        return $this;
    }

    public function loadDiscoveryLocations(): self
    {
        $this->container->invoke(LoadDiscoveryLocations::class);

        return $this;
    }

    public function loadDiscovery(): self
    {
        $this->container->addInitializer(DiscoveryCacheInitializer::class);
        $this->container->invoke(LoadDiscoveryClasses::class);

        return $this;
    }

    public function loadConfig(): self
    {
        $this->container->addInitializer(ConfigCacheInitializer::class);
        $this->container->invoke(LoadConfig::class);

        return $this;
    }

    public function registerInternalStorage(): self
    {
        $path = $this->root . '/.tempest';

        if (! is_dir($path)) {
            if (file_exists($path)) {
                throw new \RuntimeException('Unable to create internal storage directory, as a file with the same name (.tempest) already exists.');
            }

            if (! mkdir($path, recursive: true)) {
                throw new \RuntimeException('Unable to create internal storage directory because of insufficient user permission on the root directory.');
            }
        } elseif (! is_writable($path)) {
            throw new \RuntimeException('Insufficient user permission to write to internal storage directory.');
        }

        $this->internalStorage = realpath($path);

        return $this;
    }

    public function finishDeferredTasks(): self
    {
        $this->container->invoke(FinishDeferredTasks::class);

        return $this;
    }

    public function event(object $event): self
    {
        if (interface_exists(EventBus::class)) {
            $this->container->get(EventBus::class)->dispatch($event);
        }

        return $this;
    }

    public function registerEmergencyExceptionHandler(): self
    {
        $environment = Environment::fromEnv();

        // During tests, PHPUnit registers its own error handling.
        if ($environment->isTesting()) {
            return $this;
        }

        // In development, we want to register a developer-friendly error
        // handler as soon as possible to catch any kind of exception.
        if (PHP_SAPI !== 'cli' && ! $environment->isProduction()) {
            new RegisterEmergencyExceptionHandler()->register();
        }

        return $this;
    }

    public function registerExceptionHandler(): self
    {
        $appConfig = $this->container->get(AppConfig::class);

        // During tests, PHPUnit registers its own error handling.
        if ($appConfig->environment->isTesting()) {
            return $this;
        }

        $handler = $this->container->get(ExceptionHandler::class);

        set_exception_handler($handler->handle(...));
        set_error_handler(fn (int $code, string $message, string $filename, int $line) => $handler->handle(
            new \ErrorException(
                message: $message,
                code: $code,
                filename: $filename,
                line: $line,
            ),
        ), error_levels: E_ERROR);

        return $this;
    }
}
