<?php

namespace bdk\TinyFrame;

use Aura\Router\RouterContainer;
use bdk\Debug;
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
                return new Alerts($container['eventManager']);
            },
            'debug' => function () {
                return Debug::getInstance();
            },
            'eventManager' => function ($container) {
                return $container['debug']->eventManager;
            },
            'head' => function ($container) {
                return new Head($container['renderer'], $container['debug']);
            },
            'renderer' => function ($container) {
                return new Renderer($container['controller']);
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
            'router' => function ($container) {
                $uriRoot = \rtrim($container['config']['uriRoot'], '/');
                $routerContainer = new RouterContainer($uriRoot ?: null);
                $routerContainer->setLoggerFactory(function () use ($container) {
                    return $container['debug']->logger;
                });
                return $routerContainer;
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
