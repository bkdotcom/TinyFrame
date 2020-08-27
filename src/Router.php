<?php

namespace bdk\TinyFrame;

use Aura\Router\RouterContainer;
use Aura\Router\Route;
use Aura\Router\Exception\RouteNotFound;
use bdk\Html;
use bdk\Str;
use Pimple\Container;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handle route matching
 */
class Router extends Component implements ContentInterface
{

    protected $routerContainer;
    protected $confirmClass = true;

    /**
     * Constructor
     *
     * @param RouterContainer $routerContainer [description]
     * @param Container       $container       [description]
     */
    public function __construct(RouterContainer $routerContainer, Container $container)
    {
        parent::__construct($container);
        $this->routerContainer = $routerContainer;
        $this->map = $routerContainer->getMap();
        $this->generator = $routerContainer->getGenerator();
    }

    /**
     * Build & register routes from container['routes'] array
     *
     * @param array $routes Route Definitions
     *
     * @return void
     */
    public function buildRoutes($routes)
    {
        $this->map->allows(['GET', 'HEAD', 'POST'])->defaults(array(
            'action' => 'index',
        ));
        // $this->debug->warn('map', $this->map);
        // $basePath = $this->container['config']['basePath'];
        foreach ($routes as $name => $props) {
            $route = $this->map->route($name, $props[0], $props[1]); // name, path, handler
            foreach ($props as $k => $v) {
                if (\is_string($k)) {
                    $route = $route->{$k}($v);
                }
            }
            /*
                Aura Router treats /foo/ differently from /foo
                // only necessary if basePath??
            */
            /*
            if (\preg_match('#^(.*)\{/.+\}$#', $props[0], $matches)) {
                $route = $this->map->route($name . '.index', $matches[1] . '/', $props[1]);
                foreach ($props as $k => $v) {
                    if (\is_string($k)) {
                        $route = $route->{$k}($v);
                    }
                }
                $this->debug->log('adding ' . $name . '.index route', $route);
            }
            */
        }
        $this->map->route('undefinedRoute', '/')
            ->wildcard('path')
            ->special(array($this, 'undefinedRouteMatcher'));
    }

    /**
     * Find default content filepath
     *
     * @param Route $route Rotue instance
     *
     * @return string|false
     */
    public function defaultFilepath(Route $route)
    {
        $this->debug->group(__METHOD__, $route->name, $route->attributes);
        // $this->debug->log('route', $route);
        if ($route->name != 'undefinedRoute') {
            // $this->debug->warn('config', $this->config);
            // $this->debug->log('route', $route);
            $pathParts = \explode(
                '\\',
                \strtolower($route->handler)
                // \substr(, \strlen($this->config['uriRoot']))
            );
            $path = implode('/', $pathParts);
            $filepaths = array(
                $this->config['dirContent'] . '/' . $path . '/' . $route->attributes['action'] . '.php',
            );
            if ($route->attributes['action'] === 'index') {
                $filepaths[] = $this->config['dirContent'] . '/' . $path . '.php';
            }
            $uriPath = \substr($this->request->getUri()->getPath(), \strlen($this->config['uriRoot']) - 1);
            // $this->debug->warn('uriPath', $this->request->getUri()->getPath(), $uriPath);
            $filepaths[] = $this->config['dirContent'] . $uriPath . '/' . $route->attributes['action'] . '.php';
            $filepaths[] = $this->config['dirContent'] . $uriPath . '/index.php';
        } elseif ($route->attributes['path']) {
            $path = $route->attributes['path'];
            //   /some/uri/   will result in path being ['some','uri','']
            $path = \array_filter($path, 'strlen');
            if (\end($path) == 'index') {
                \array_pop($path);
            }
            $path = \substr(\implode('/', $path), \strlen($this->config['uriRoot']));
            $filepaths = array(
                $this->config['dirContent'] . '/' . $path . '/index.php',
                $this->config['dirContent'] . '/' . $path . '.php',
            );
        } else {
            $path = $this->request->getUri()->getPath();
            $path = substr($path, strlen($this->config['uriRoot']));
            $this->debug->log('path', $path);
            $path = explode('/', $path);
            $path = \array_filter($path, 'strlen');
            if (\end($path) == 'index') {
                \array_pop($path);
            }
            $filepaths = array(
                $this->config['dirContent'] . '/' . \implode('/', $path) . '/index.php',
                $this->config['dirContent'] . '/' . \implode('/', $path) . '.php',
            );
        }
        $this->debug->log('filepaths', $filepaths);
        foreach ($filepaths as $filepath) {
            if (\file_exists($filepath)) {
                $this->debug->log('filepath found', $filepath);
                $this->debug->groupEnd();
                return $filepath;
            }
        }
        $this->debug->warn('default filepath not found');
        $this->debug->groupEnd();
        return false;
    }

    public function getContentGenerators()
    {
        return array(
            'getUrl',
        );
    }

    /**
     * get page link
     *
     * @param string $nameOrPath route name or url path
     * @param array  $params     path attributes & query params
     * @param array  $opts       buildUrl options
     *
     * @return string
     */
    public function getUrl($nameOrPath, $params = array(), $opts = array())
    {
        $this->debug->group(__METHOD__, $nameOrPath);
        // $this->debug->warn('getUrl', $nameOrPath, $params);
        $this->getUrlParams($params, $opts);
        /*
        $this->debug->warn(array(
            'nameOrPath' => $nameOrPath,
            'params' => $params,
            'opts' => $opts,
        ));
        */
        try {
            $route = null;
            if (isset($this->routes[$nameOrPath])) {
                // named route
                $route = $this->map->getRoute($nameOrPath);
                $this->debug->info('found named route', $nameOrPath);
                /*
                if ($nameOrPath == "wrapper" && !$this->foo) {
                    $this->foo = true;
                    $this->debug->log('route', $route);
                }
                */
            } else {
                // $this->debug->warn('foo');
                if (false && $params) {
                    // $this->debug->log('routerContainer', $this->routerContainer);
                    $routes = $this->routerContainer->getMap()->getRoutes();
                    // $this->debug->log('routes', $routes);
                    /*
                    $path = new \Aura\Router\Rule\Path('/');
                    $refMethod = new \ReflectionMethod('Aura\\Router\\Rule\\Path', 'buildRegex');
                    $refMethod->setAccessible(true);
                    foreach ($routes as $route) {
                        $regex = $refMethod->invoke($path, $route);
                        $this->debug->log('regex', $regex);
                    }
                    */
                } else {
                    $request = new Request('GET', \str_replace('//', '/', $this->config['uriRoot'] . $nameOrPath));
                    $this->confirmClass = false;
                    $route = $this->getRequestRoute($request);
                    $this->confirmClass = true;
                }
                // $this->debug->log('route', $route);
                if (!$route || $route->name == 'undefinedRoute') {
                    $this->debug->warn('RouteNotFound');
                    throw new RouteNotFound();
                }
                $this->debug->log('route->name', $route->name);
            }
            if (isset($params[$route->wildcard]) && \is_string($params[$route->wildcard])) {
                $params[$route->wildcard] = \explode('/', $params[$route->wildcard]);
            }
            // $this->debug->log('route', $route);
            $params = array_merge(
                // \array_fill_keys(\array_keys($route->defaults), null),
                // $route->defaults ?: array(),
                $route->attributes,
                $params
            );
            // $this->debug->log('params', $params);
            $path = $this->generator->generate($route->name, $params);
            /*
                get attribute keys
                we don't want to include attributes as params
            */
            $keys = $this->getRouteAttributeKeys($route);
            $params = \array_diff_key($params, \array_flip($keys));
            // $this->debug->log('path', $path);
            // $this->debug->log('keys', $keys);
            // $this->debug->log('params', $params);
            // $this->debug->log('route', $route);
            /*
                We don't want route defaults in our generated path
            */
            $defaultAction = isset($route->defaults['action'])
                ? $route->defaults['action']
                : null;
            // $this->debug->warn('defaultAction', $defaultAction, $path);
            $strpos = -1 - strlen($defaultAction);
            /*
            $this->debug->warn('path', $path);
            $this->debug->log('defaultAction', $defaultAction);
            $this->debug->log('substr', \substr($path, $strpos));
            $this->debug->log('route defaults', $route->defaults);
            $this->debug->log('route attributes', $route->attributes);
            */
            if ($defaultAction && \substr($path, $strpos) == '/' . $defaultAction) {
                // $this->debug->wran('boo', $path);
                $path = \substr($path, 0, $strpos);
                // $this->debug->wran('boo2', $path);
            }
            if (!$path) {
                $path = '/';
            }

        } catch (RouteNotFound $e) {
            $this->debug->info('RouteNotFound', $nameOrPath, $params);
            // $this->debug->log('e', $e);
            $path = $nameOrPath;
            if ($path{0} === '/') {
                /*
                    Absolute Path
                */
                $path = \substr($this->config['uriRoot'], 0, -1) . $path;
            } else {
                /*
                    Relative path
                */
                $this->debug->log('route', $this->route);
                $pathBase = $this->request->getUri()->getPath();
                $this->debug->log('pathBase', $pathBase);
                // $this->debug->log('route path', $this->route->path);
                // preg_match('#^(.*?)(\{/?action\})#')
                // $this->debug->warn('attributes', $this->request->getAttributes());
                if ($this->request->getAttribute('action', 'index') === 'index') {
                    $path = $pathBase . '/' . $path;
                } else {
                    $pathBase = \explode('/', $pathBase);
                    \array_pop($pathBase);
                    $pathBase[] = $path;
                    $path = \implode('/', $pathBase);
                }
            }
        }
        /*
        $query = \http_build_query($params);
        if ($query) {
            $this->debug->log('query', $query);
            $query = \str_replace(array('%2F','%2C','%3A','%7C'), array('/',',',':','|'), $query);
            $url = $url.'?'.$query;
        }
        */
        $urlParts = array(
            'path' => $path,
            'params' => $params,
        );
        if ($opts['fullUrl']) {
            $urlParts['scheme'] = $this->request->isSecure() ? 'https' : 'http';
            $urlParts['host']   = $_SERVER['HTTP_HOST'];
        }
        $url = Html::buildUrl($urlParts);
        $url = \str_replace(array('%2F','%2C','%3A','%7C'), array('/',',',':','|'), $url);
        if ($opts['chars']) {
            $url = \htmlspecialchars($url);
        }
        $this->debug->groupEnd($url);
        return $url;
    }

    /**
     * Find matching route for request
     *
     * @param ServerRequestInterface $request ServerRequest obj
     *
     * @return Route
     */
    public function getRequestRoute(ServerRequestInterface $request)
    {
        $this->debug->groupCollapsed(__METHOD__);
        // $this->debug->log('request', $request);
        $matcher = $this->routerContainer->getMatcher();
        $route = $matcher->match($request);
        // $this->debug->info('route', $route ? $route->name : $route);
        if ($route && $route->name == 'undefinedRoute') {
            $this->debug->log('undefinedRoute');
            $uri = $request->getUri();
            $path = $uri->getPath();
            if (substr($path, -1) == '/') {
                $this->debug->log('try without trailing /');
                // $this->debug->warn('path', $path);
                $uri = $uri->withPath(preg_replace('#/$#', '', $path));
                // $this->debug->log('uri', $uri);
                $request = $this->request->withUri($uri);
                $route = $matcher->match($request);
                if ($route) {
                    return $route;
                }
            }
        }
        if (!$route) {
            $this->debug->warn('no matching defined route');
            $route = $matcher->getFailedRoute();
        }
        /*
        if (empty($route->extras['filepath'])) {
            $filepath = $this->defaultFilepath($route);
            if ($filepath) {
                $route->extras(array(
                    'filepath' => $filepath,
                ));
            }
        }
        */
        /*
        $action = $route->attributes['action'];
        $this->debug->log('action', $action);
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['action']) && $action == 'index') {
            // use queryParam action
            $route->attributes(array(
                'action'=>$queryParams['action'],
            ));
        }
        */
        $this->debug->log('route', $route);
        $this->debug->groupEnd();
        return $route;
    }

    /**
     * Special matching logic for fallback route
     *
     * @param Request $request Request instance
     * @param Route   $route   Route instance
     *
     * @return boolean
     */
    public function undefinedRouteMatcher(Request $request, Route $route)
    {
        $this->debug->group(__METHOD__);
        // $this->debug->log('request', $request);
        // $this->debug->log('route', $route);

        $classExists = false;
        if (!$this->confirmClass) {
            $this->debug->groupEnd();
            return false;
        }
        /*
            Find controller & action
        */
        $path = $route->attributes['path'];
        $path = \array_values(\array_filter($path, 'strlen'));
        $route->attributes(array('path' => null));
        $check = array();
        if ($path) {
            $check[] = array($path, 'index');
        }
        if (\count($path) > 2) {
            $check[] = array(\array_slice($path, 0, -1), \end($path));
        }
        if (\count($path) > 1) {
            $action = $path[1];
            $check[] = array(array($path[0]), $action);
        }
        $this->debug->log('check', $check);
        foreach ($check as $pathAction) {
            list($path, $action) = $pathAction;
            $count = \count($path);
            if ($count) {
                $path[$count - 1] = \ucfirst(Str::toCamelCase($path[$count - 1]));
            }
            $classnameTest = '\\' . $this->config['controllerNamespace'] . '\\' . \implode('\\', $path);
            $this->debug->log('check if class exists', $classnameTest);
            /*
            if ($classnameTest == '\\BKDotCom\\controllers\\Journal') {
                $this->debug->log($this);
            }
            */
            if (\class_exists($classnameTest)) {
                /*
                    don't check if action is a valid method yet
                */
                $this->debug->info('found controller', $classnameTest);
                $this->debug->log('action', $action);
                $route->attributes(array(
                    'action' => $action,
                    // 'path' => \array_slice($route->attributes['path'], \count($path) + 1),
                ))->handler($classnameTest);
                $classExists = true;
                break;
            }
        }
        if (!$classExists) {
            $route
                ->handler('\\bdk\\TinyFrame\\Controller')
                ->attributes(array(
                    'action' => 'defaultAction',
                ));
            $classExists = true;
        }
        $this->debug->groupEnd();
        return $classExists;    //  || $filepath;
    }

    protected function getRouteAttributeKeys(Route $route)
    {
        \preg_match_all('#{([a-z][a-zA-Z0-9_]*)}#', $route->path, $matchesReq);
        \preg_match('#{/([a-z][a-zA-Z0-9_,]*)}#', $route->path, $matchesOpt);
        $keys = \array_merge(
            \array_keys($route->defaults),
            $matchesReq ? $matchesReq[1] : array(),
            $matchesOpt ? \explode(',', $matchesOpt[1]) : array()
        );
        if ($route->wildcard) {
            $keys[] = $route->wildcard;
        }
        return $keys;
    }

    /**
     * [getUrlParams description]
     *
     * @param array $params url parameters
     * @param array $opts   options
     *
     * @return void
     */
    protected function getUrlParams(&$params = array(), &$opts = array())
    {
        // $this->debug->groupCollapsed(__CLASS__.'->'.__FUNCTION__);
        $optsDefault = array(
            'fullUrl' => false,
            'chars' => true,
            'getProp' => array('m'),
        );
        $args = \func_get_args();
        $argCount = 2;
        $argCountPossible = 2;
        for ($i = 1; $i > 0; $i--) {
            if ($args[$i] === array()) {
                $argCount--;
            } else {
                break;
            }
        }
        /*
        $this->debug->warn(array(
            'params' => $params,
            'opts' => $opts,
        ));
        */
        for ($i = 0; $i < $argCountPossible; $i++) {
            if (!\is_bool($args[$i]) && !\is_array($args[$i])) {
                $args[$i] = array();
            } elseif (\is_bool($args[$i]) || \array_intersect(array_keys($optsDefault), \array_keys($args[$i]))) {
                // $this->debug->warn('opts found', $i, $args[$i]);
                $args[1] = $args[$i];
                if ($i !== 1) {
                    $args[$i] = array();
                }
                break;
            }
        }
        // $this->debug->log('args', $args);
        $args = \array_combine(array('params','opts'), $args);
        \extract($args);
        if (\is_bool($opts)) {
            $opts = array('fullUrl' => $opts);
        }
        $opts = \array_merge($optsDefault, $opts);
        if ($opts['getProp']) {
            $propagateParams = array_intersect_key($this->request->getQueryParams(), \array_flip($opts['getProp']));
            $params = array_merge($propagateParams, $params);
        }
        /*
        if (!isset($opts['getProp'])) {
            $opts['getProp'] = array();
        }
        */
        // $this->debug->groupEnd();
    }
}
