<?php

namespace bdk\TinyFrame;

use Aura\Router\RouterContainer;
use bdk\Debug;
use bdk\Debug\LogEntry;
use bdk\Session;
use bdk\TinyFrame\Request;
use GuzzleHttp\Psr7\Response;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

/**
 * Define services
 */
class ServiceProvider implements ServiceProviderInterface
{

    /**
     * Register our "services"
     *
     * @param Container $container [description]
     *
     * @return void
     */
    public function register(Container $container)
    {
        $services = array(
            'alerts' => function ($container) {
                $alerts = new Alerts($container['eventManager']);
                $sessionAlerts = $container['session']->get('alerts');
                if ($sessionAlerts) {
                    foreach ($sessionAlerts as $alert) {
                        $alerts->add($alert);
                    }
                    $container['session']->remove('alerts');
                }
                return $alerts;
            },
            'auraRouterContainer' => function ($container) {
                $uriRoot = \rtrim($container['config']['uriRoot'], '/');
                $routerContainer = new RouterContainer($uriRoot ?: null);
                /*
                $routerContainer->setLoggerFactory(function () use ($container) {
                    $debug = $container['debug']->getChannel('Aura');
                    $debug->eventManager->subscribe(Debug::EVENT_OUTPUT_LOG_ENTRY, function (LogEntry $logEntry) {
                        // Debug::_warn('logEntry', $logEntry);
                        $logEntry->setMeta('icon', 'fa fa-random');
                    });
                    return $debug->logger;
                });
                */
                return $routerContainer;
            },
            'content' => function ($container) {
                return new Content($container);
            },
            'debug' => function () {
                return Debug::getInstance();
            },
            'errorHandler' => function ($container) {
                return $container['debug']->errorHandler;
            },
            'eventManager' => function ($container) {
                return $container['debug']->eventManager;
            },
            'head' => function ($container) {
                return new Head($container);
            },
            'renderer' => function ($container) {
                return new Renderer($container);
            },
            'request' => function () {
                // allow "." & ' ' in keys
                $getBackup = $_GET;
                $_GET = \bdk\Str::parse($_SERVER['QUERY_STRING']);
                $request = Request::fromGlobals();
                $_GET = $getBackup;
                return $request;
            },
            // Response is immutable...
            'response' => $container->factory(function () {
                return new Response();
            }),
            'route' => function ($container) {
                return $container['router']->getRequestRoute($container['request']);
            },
            'router' => function ($container) {
                return new Router($container['auraRouterContainer'], $container);
            },
            'session' => function () {
                return new Session();
            },
        );
        foreach ($services as $k => $v) {
            if (!isset($container[$k])) {
                $container[$k] = $v;
            }
        }

        /*
        $container['content'] = function ($container) {
            return new Content($container['currentPage'], $container['debug']);
        };
        if (!isset($container['debug'])) {
            $container['debug'] = function () {
            };
        }
        $container['edit'] = function () {
            return new Edit();
        };
        $container['extend'] = function ($container) {
            return new Extend($container['site'], $container['debug']);
        };
        $container['head'] = function ($container) {
            return new Head($container['site'], $container['currentPage'], $container['debug']);
        };
        */
    }
}
