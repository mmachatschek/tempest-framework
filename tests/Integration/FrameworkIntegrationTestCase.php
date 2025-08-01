<?php

declare(strict_types=1);

namespace Tests\Tempest\Integration;

use Closure;
use InvalidArgumentException;
use Tempest\Console\ConsoleApplication;
use Tempest\Console\Input\ConsoleArgumentBag;
use Tempest\Console\Output\MemoryOutputBuffer;
use Tempest\Console\Output\StdoutOutputBuffer;
use Tempest\Console\OutputBuffer;
use Tempest\Console\Testing\ConsoleTester;
use Tempest\Core\Application;
use Tempest\Core\ShellExecutor;
use Tempest\Core\ShellExecutors\NullShellExecutor;
use Tempest\Database\DatabaseInitializer;
use Tempest\Database\Migrations\MigrationManager;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Framework\Testing\IntegrationTest;
use Tempest\Reflection\MethodReflector;
use Tempest\Router\HttpApplication;
use Tempest\Router\Route;
use Tempest\Router\RouteConfig;
use Tempest\Router\Routing\Construction\DiscoveredRoute;
use Tempest\Router\Routing\Construction\RouteConfigurator;
use Tempest\Router\Static\StaticPageConfig;
use Tempest\Router\StaticPage;
use Tempest\View\GenericView;
use Tempest\View\View;
use Tempest\View\ViewComponent;
use Tempest\View\ViewConfig;
use Tempest\View\ViewRenderer;
use Throwable;

use function Tempest\Support\Path\normalize;

abstract class FrameworkIntegrationTestCase extends IntegrationTest
{
    protected function setUp(): void
    {
        // We force forward slashes for consistency even on Windows.
        $this->root = normalize(realpath(__DIR__ . '/../../'));
        $this->discoveryLocations = [
            new DiscoveryLocation('Tests\\Tempest\\Integration\\Console\\Fixtures', __DIR__ . '/Console/Fixtures'),
            new DiscoveryLocation('Tests\\Tempest\\Fixtures', __DIR__ . '/../Fixtures'),
        ];

        parent::setUp();

        // Console
        $this->container->singleton(OutputBuffer::class, fn () => new MemoryOutputBuffer());
        $this->container->singleton(StdoutOutputBuffer::class, fn () => new MemoryOutputBuffer());
        $this->container->singleton(ShellExecutor::class, fn () => new NullShellExecutor());

        $this->console = new ConsoleTester($this->container);

        // Database
        $this->container
            ->removeInitializer(DatabaseInitializer::class)
            ->addInitializer(TestingDatabaseInitializer::class);

        $databaseConfigPath = __DIR__ . '/../Fixtures/Config/database.config.php';

        if (! file_exists($databaseConfigPath)) {
            copy(__DIR__ . '/../Fixtures/Config/database.sqlite.php', $databaseConfigPath);
        }

        $this->container->config(require $databaseConfigPath);

        $this->rollbackDatabase();
    }

    protected function actAsConsoleApplication(string $command = ''): Application
    {
        $application = new ConsoleApplication(
            container: $this->container,
            argumentBag: new ConsoleArgumentBag(['tempest', ...explode(' ', $command)]),
        );

        $this->container->singleton(Application::class, fn () => $application);

        return $application;
    }

    protected function actAsHttpApplication(): HttpApplication
    {
        $application = new HttpApplication(
            $this->container,
        );

        $this->container->singleton(Application::class, fn () => $application);

        return $application;
    }

    protected function render(string|View $view, mixed ...$params): string
    {
        if (is_string($view)) {
            $view = new GenericView($view);
        }

        $view->data(...$params);

        return $this->container->get(ViewRenderer::class)->render($view);
    }

    protected function registerViewComponent(string $name, string $html, string $file = '', bool $isVendor = false): void
    {
        $viewComponent = new ViewComponent(
            name: $name,
            contents: $html,
            file: $file,
            isVendorComponent: $isVendor,
        );

        $this->container->get(ViewConfig::class)->addViewComponent($viewComponent);
    }

    protected function rollbackDatabase(): void
    {
        $migrationManager = $this->container->get(MigrationManager::class);

        $migrationManager->dropAll();
    }

    protected function assertStringCount(string $subject, string $search, int $count): void
    {
        $this->assertSame($count, substr_count($subject, $search));
    }

    protected function registerRoute(array|string|MethodReflector $action): void
    {
        $reflector = match (true) {
            $action instanceof MethodReflector => $action,
            is_array($action) => MethodReflector::fromParts(...$action),
            default => MethodReflector::fromParts($action, '__invoke'),
        };

        if ($reflector->getAttribute(Route::class) === null) {
            throw new InvalidArgumentException('Missing route attribute');
        }

        $configurator = $this->container->get(RouteConfigurator::class);
        $configurator->addRoute(
            DiscoveredRoute::fromRoute(
                $reflector->getAttribute(Route::class),
                $reflector,
            ),
        );

        $routeConfig = $this->container->get(RouteConfig::class);
        $routeConfig->apply($configurator->toRouteConfig());
    }

    protected function registerStaticPage(array|string|MethodReflector $action): void
    {
        $reflector = match (true) {
            $action instanceof MethodReflector => $action,
            is_array($action) => MethodReflector::fromParts(...$action),
            default => MethodReflector::fromParts($action, '__invoke'),
        };

        if ($reflector->getAttribute(StaticPage::class) === null) {
            throw new InvalidArgumentException('Missing static page attribute');
        }

        $this->container->get(StaticPageConfig::class)->addHandler(
            $reflector->getAttribute(StaticPage::class),
            $reflector,
        );
    }

    protected function assertSnippetsMatch(string $expected, string $actual): void
    {
        $expected = str_replace([PHP_EOL, ' '], '', $expected);
        $actual = str_replace([PHP_EOL, ' '], '', $actual);

        $this->assertSame($expected, $actual);
    }

    protected function assertSameWithoutBackticks(string $expected, string $actual): void
    {
        $clean = function (string $string): string {
            return \Tempest\Support\str($string)
                ->replace('`', '')
                ->replaceRegex('/AS \"(?<alias>.*?)\"/', fn (array $matches) => "AS {$matches['alias']}")
                ->toString();
        };

        $this->assertSame(
            $clean($expected),
            $clean($actual),
        );
    }

    protected function assertException(
        string $expectedExceptionClass,
        Closure $handler,
        ?Closure $assertException = null,
    ): void {
        try {
            $handler();
        } catch (Throwable $throwable) {
            $this->assertInstanceOf($expectedExceptionClass, $throwable);

            if ($assertException !== null) {
                $assertException($throwable);
            }

            return;
        }

        /* @phpstan-ignore-next-line */
        $this->assertTrue(false, "Expected exception {$expectedExceptionClass} was not thrown");
    }

    protected function skipWindows(string $reason): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        $this->markTestSkipped($reason);
    }

    /**
     * @template TClassName of object
     * @param class-string<TClassName> $className
     * @return null|TClassName
     */
    protected function get(string $className): ?object
    {
        return $this->container->get($className);
    }
}
