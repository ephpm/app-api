<?php

declare(strict_types=1);

use App\Environment;
use Ephpm\Psr15\Worker as EphpmWorker;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LogLevel;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\ErrorHandler\Renderer\PlainTextRenderer;
use Yiisoft\Log\Logger;
use Yiisoft\Log\StreamTarget;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;
use Yiisoft\Yii\Runner\ApplicationRunner;

require_once __DIR__ . '/src/bootstrap.php';

/*
 * ePHPm worker mode. ePHPm hands each request to take_request(); the ephpm/psr15-worker package turns
 * it into a PSR-7 request, calls the handler below and sends the PSR-7 response. As in the other
 * worker entry points, Yii boots once, the container is reset after every response, and ePHPm
 * recycles the worker after [php.worker] max_requests (10,000) by making take_request() return null.
 */
$runner = new class (
    rootPath: __DIR__,
    debug: Environment::appDebug(),
    checkEvents: Environment::appDebug(),
    environment: Environment::appEnv(),
    bootstrapGroup: 'bootstrap-web',
    eventsGroup: 'events-web',
    diGroup: 'di-web',
    diProvidersGroup: 'di-providers-web',
    diDelegatesGroup: 'di-delegates-web',
    diTagsGroup: 'di-tags-web',
    paramsGroup: 'params-web',
    nestedParamsGroups: ['params'],
    nestedEventsGroups: ['events'],
) extends ApplicationRunner {
    public function run(): void
    {
        $temporaryErrorHandler = new ErrorHandler(
            new Logger([(new StreamTarget())->setLevels([LogLevel::EMERGENCY, LogLevel::ERROR, LogLevel::WARNING])]),
            new PlainTextRenderer(),
        );
        $temporaryErrorHandler->register();
        $container = $this->getContainer();
        $errorHandler = $container->get(ErrorHandler::class);
        $temporaryErrorHandler->unregister();
        if ($this->debug) {
            $errorHandler->debug();
        }
        $errorHandler->register();
        $this->runBootstrap();
        $this->checkEvents();

        $application = $container->get(Application::class);

        $handler = new class ($application, $container) implements RequestHandlerInterface {
            public ?ResponseInterface $response = null;

            public function __construct(
                private readonly Application $application,
                private readonly \Psr\Container\ContainerInterface $container,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->response = null;
                $request = $request->withAttribute('applicationStartTime', microtime(true));
                try {
                    $this->response = $this->application->handle($request);
                } catch (Throwable $throwable) {
                    $this->response = $this->container->get(ErrorCatcher::class)
                        ->process($request, new ThrowableHandler($throwable));
                }

                return $this->response;
            }
        };
        $worker = new EphpmWorker($handler);

        $application->start();
        while (($envelope = \Ephpm\Worker\take_request()) !== null) {
            try {
                // Builds the PSR-7 request, runs $handler and sends the response exactly once.
                $worker->handleEnvelope($envelope);
            } finally {
                $application->afterEmit($handler->response);
                // Resolve after handling so lazily created services are included.
                $container->get(StateResetter::class)->reset();
                gc_collect_cycles();
            }
        }
        $application->shutdown();
    }
};
$runner->run();
