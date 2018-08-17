<?php

namespace bdk;

use bdk\TinyFrame\ServiceProvider;
use Aura\Router\Route;
use Pimple\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
        if (!isset($container['config'])) {
            $container['config'] = array();
        }
		$this->container = $container;
        $this->container['config'] = array_replace_recursive(array(
            'uriRoot' => $this->getUriRoot(),
            'uriContent' => $this->getUriContent(),
            'dirRoot' => $this->getDirRoot(),
            'dirContent' => $this->getDirContent(),
            'template' => $this->getDirRoot().'/template.html',
            'controllerNamespace' => null,
        ), $container['config']);
        $container['debug']->log('config', $container['config']);
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
        return null;
    }

    /**
     * Build & register routes from container['routes'] array
     *
     * @return void
     */
	public function buildRoutes()
	{
        $map = $this->router->getMap();
        $map->allows(['GET', 'POST'])->defaults(array(
            'action' => 'index',
        ));
        // $basePath = $this->container['config']['basePath'];
        foreach ($this->routes as $name => $props) {
            $route = $map->route($name, $props[0], $props[1]);
            foreach ($props as $k => $v) {
                if (\is_string($k)) {
                    $route = $route->{$k}($v);
                }
            }
            /*
                Aura Router treats /foo/ differently from /foo
                // only necessary if basePath??
            */
            if (\preg_match('#^(.*)\{/.+\}$#', $props[0], $matches)) {
                $route = $map->route($name.'.index', $matches[1].'/', $props[1]);
                foreach ($props as $k => $v) {
                    if (\is_string($k)) {
                        $route = $route->{$k}($v);
                    }
                }
            }
        }
        $map->route('undefinedRoute', '/')->wildcard('path')->special(array($this, 'undefinedRouteMatcher'));
	}

    /**
     * Special matching logic for fallback route
     *
     * @param ServerRequestInterface $request Request instance
     * @param Route                  $route   Route instance
     *
     * @return boolean
     */
    public function undefinedRouteMatcher(ServerRequestInterface $request, Route $route)
    {
        $this->debug->group(__METHOD__);
        $classExists = false;
        /*
            Find controller & action
        */
        $path = $route->attributes['path'];
        $check = array();
        if ($path) {
            $check[] = array($path, 'index');
        }
        if (\count($path) > 1) {
            $action = \array_pop($path);
            $check[] = array($path, $action);
        }
        foreach ($check as $pathAction) {
            list($path, $action) = $pathAction;
            $count = \count($path);
            $path[$count-1] = \ucfirst($path[$count-1]);
            $classnameTest = '\\'.$this->container['config']['controllerNamespace'].'\\'.\implode('\\', $path);
            if (\class_exists($classnameTest)) {
                /*
                    don't check if action is a valid method yet
                */
                $route->attributes(array(
                    'action' => $action,
                ))->handler($classnameTest);
                $classExists = true;
                break;
            }
        }
        /*
            Find default content filepath
        */
        $contentFilepath = $this->defaultContentFilepath($route);
        if (!$contentFilepath && !$classExists) {
            $route->attributes(array(
                'action' => 'defaultAction',
            ))->handler('\\bdk\\TinyFrame\\Controller');
        }
        $this->debug->groupEnd();
        return $classExists || $contentFilepath;
    }

	/**
     * [dispatchRoute description]
     *
     * @param Route $route Route instance
     *
     * @return void
     */
    public function dispatchRoute(Route $route)
	{
        $this->debug->group(__METHOD__);
        $this->debug->log('route', $route);
        $classname = $route->handler;
        if ($classname{0} !== '\\') {
            $classname = '\\'.$this->container['config']['controllerNamespace'].'\\'.$classname;
        }
        $this->container['controller'] = new $classname($this->container);
        foreach ($route->extras as $k => $v) {
            $this->container['controller']->{$k} = $v;
        }
        $action = 'action'.\ucfirst($route->attributes['action']);
        if (!\method_exists($this->container['controller'], $action)) {
            $this->debug->warn('method '.$action.' does not exist, using defaultAction');
            $action = 'defaultAction';
        }
        $this->debug->log(array(
            'controller' => $this->container['controller'],
            'action' => $action,
        ));
        $this->container['controller']->{$action}();
        $this->debug->groupEnd();
	}

	/**
     * Find matching route for request
     *
     * @return Route
     */
    public function getRequestRoute()
	{
		$this->debug->group(__METHOD__);
        $this->debug->log('request', $this->request);
        $matcher = $this->router->getMatcher();
        $route = $matcher->match($this->request);
        if (!$route) {
            $this->debug->warn('404');
            $route = $matcher->getFailedRoute();
        }
        if (empty($route->extras['filepath'])) {
            $this->defaultContentFilepath($route);
        }
        $action = $route->attributes['action'];
        $queryParams = $this->request->getQueryParams();
        if (isset($queryParams['action']) && $action == 'index') {
            // use queryParam action
            $route->attributes(array(
                'action'=>$queryParams['action'],
            ));
        }
        foreach ($route->attributes as $k => $v) {
            $this->request = $this->request->withAttribute($k, $v);
        }
        $this->debug->groupEnd();
        return $route;
	}

	/**
     * Output our page
     *
     * @return void
     */
    public function run()
	{
        $this->buildRoutes();
        $route = $this->getRequestRoute();
        $this->dispatchRoute($route);
        // $this->sendResponse($response);
	}

    /**
     * Output response
     *
     * @param ResponseInterface $response Response instance
     *
     * @return void
     */
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

    /**
     * Find default content filepath
     *
     * @param Route $route Rotue instance
     *
     * @return string|false
     */
    protected function defaultContentFilepath(Route $route)
    {
        if ($route->name != 'undefinedRoute') {
            $path = \explode('\\', \strtolower($route->handler));
            $filepaths = array(
                $this->config['dirContent'].'/'.\implode('/', $path).'/'.$route->attributes['action'].'.php',
            );
            if (\count($path) == 1) {
                $filepaths[] = $this->config['dirContent'].'/'.$route->attributes['action'].'.php';
            }
        } elseif ($route->attributes['path']) {
            $path = $route->attributes['path'];
            $filepaths = array(
                $this->config['dirContent'].'/'.\implode('/', $path).'/index.php',
                $this->config['dirContent'].'/'.\implode('/', $path).'.php',
            );
        } else {
            $filepaths = array(
                $this->config['dirContent'].'/index.php',
            );
        }
        $this->debug->log('filepaths', $filepaths);
        foreach ($filepaths as $filepath) {
            if (\file_exists($filepath)) {
                $route->extras(array(
                    'filepath' => $filepath,
                ));
                return $filepath;
            }
        }
        return false;
    }

    protected function getDirRoot()
    {
        if (isset($this->container['config']['dirRoot'])) {
            return $this->container['config']['dirRoot'];
        }
        $backtrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
        $frame = \array_pop($backtrace);
        return \dirname($frame['file']);
    }

    protected function getDirContent()
    {
        if (isset($this->container['config']['dirContent'])) {
            return $this->container['config']['dirContent'];
        }
        return $this->getDirRoot().'/content';
    }

    protected function getUriRoot()
    {
        if (isset($this->container['config']['uriRoot'])) {
            return $this->container['config']['uriRoot'];
        }
        $return = \dirname($_SERVER['SCRIPT_NAME']);
        $return = \rtrim($return, '/').'/';
        return $return;
        // return \dirname($_SERVER['SCRIPT_NAME']).'/';
        // $return = \preg_replace('#(\\\/|//)#', '/', $return);
    }

    protected function getUriContent()
    {
        if (isset($this->container['config']['uriContent'])) {
            return $this->container['config']['uriContent'];
        }
        $dirDocRoot = \realpath($_SERVER['DOCUMENT_ROOT']);
        $dirContent = $this->getDirContent();
        $dirRoot = $this->getDirRoot();
        if (\strpos($dirContent, $dirDocRoot) === 0) {
            $this->debug->log('root is ancestor');
            $return = substr($dirContent, \strlen($dirDocRoot));
        } elseif (\strpos($dirContent, $dirRoot) === 0) {
            $this->debug->log('site is ancestor');
            $relpath = substr($dirContent, \strlen($dirRoot));
            $return = \str_replace('//', '/', $this->getUriSite().$relpath);
        } else {
            $this->debug->warn('dirContent is outside of DocumentRoot and site directory ¯\_(ツ)_/¯');
            // there's likely a symlink -> unable to resolve
            $return = $this->getUriSite();
        }
        // make sure ends in a single /
        $return = \rtrim($return, '/').'/';
        return $return;
    }
}
