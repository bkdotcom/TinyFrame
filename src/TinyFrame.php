<?php

namespace bdk;

use bdk\TinyFrame\ServiceProvider;
use Pimple\Container;
use Psr\Http\Message\ResponseInterface;

/**
 * TinyFramework
 */
class TinyFrame
{

	public $container;

	/**
	 * Constructor
	 *
	 * @param array|Container $container container instance
	 */
	public function __construct($container)
	{
        if (\is_array($container)) {
            $container = new Container($container);
        }
		$this->container = $container;
        $this->container->register(new ServiceProvider());
	}

    /**
     * __get magic method
     *
     * @param string $key property to get
     *
     * @return mixed
     */
    public function __get($key)
    {
        if ($this->container->offsetExists($key)) {
            return $this->container[$key];
        }
        /*
        $getter = 'get'.\ucfirst($key);
        if (\method_exists($this, $getter)) {
            return $this->{$getter}();
        }
        $props = \get_object_vars($this);
        foreach ($props as $prop) {
            if (\is_object($prop) && \method_exists($prop, $getter)) {
                return $prop->{$getter}();
            }
        }
        */
        return null;
    }

	public function buildRoutes()
	{
        $map = $this->router->getMap();
        $map->allows(['GET', 'POST']);
        foreach ($this->routes as $name => $props) {
            $route = $map->route($name, $props[0], $props[1]);
            foreach ($props as $k => $v) {
                if (\is_string($k)) {
                    $route = $route->{$k}($v);
                }
            }
            /*
                Aura Router treats /foo/ differently from /foo
            */
            if (\preg_match('#^(.*)\{/.+\}$#', $props[0], $matches)) {
                $this->debug->log('adding slash route', $matches[1].'/');
                $route = $map->route($name.'.index', $matches[1].'/', $props[1]);
                foreach ($props as $k => $v) {
                    if (\is_string($k)) {
                        $route = $route->{$k}($v);
                    }
                }
            }
        }
	}

	public function dispatchRoute($route)
	{
        $this->debug->group(__METHOD__);
        $classname = $route->handler;
        $this->container['controller'] = new $classname($this->container);
        $action = 'action'.\ucfirst($route->attributes['action']);
        $this->debug->log(array(
        	'controller' => $this->container['controller'],
        	'action' => $action,
        ));
        $this->container['controller']->{$action}();
        $this->debug->groupEnd();
	}

	public function getRequestRoute()
	{
		$matcher = $this->router->getMatcher();
        $route = $matcher->match($this->request);
        if (!$route) {
            $route = $matcher->getFailedRoute();
        }
        $action = $route->attributes['action'];
        $queryParams = $this->request->getQueryParams();
        if (isset($queryParams['action']) && $action == 'index') {
            $route->attributes(array(
                'action'=>$queryParams['action'],
            ));
        }
        foreach ($route->attributes as $k => $v) {
            $this->request = $this->request->withAttribute($k, $v);
        }
        return $route;
	}

	public function run()
	{
        $this->debug->log('request', $this->request);
        $this->buildRoutes();
        $route = $this->getRequestRoute();
        $this->debug->log('route', $route);
        $response = $this->dispatchRoute($route);
        // $this->sendResponse($response);
	}

    public function sendResponse(ResponseInterface $response)
    {
        $httpLine = \sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );
        \header($httpLine, true, $response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                \header("$name: $value", false);
            }
        }
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        while (!$stream->eof()) {
            echo $stream->read(1024 * 8);
        }
    }
}
