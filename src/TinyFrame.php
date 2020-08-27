<?php

namespace bdk;

use bdk\TinyFrame\Component;
use bdk\TinyFrame\Controller;
use bdk\TinyFrame\ExceptionController;
use bdk\PubSub\Event;
// use bdk\TinyFrame\Exception\ExitException;
// use bdk\TinyFrame\Exception\HttpException;
use bdk\TinyFrame\Exception\RedirectException;
use bdk\TinyFrame\Exception\InvalidArgument;
use ErrorException;
use bdk\TinyFrame\Request;
use bdk\TinyFrame\ServiceProvider;
use bdk\Str;
use Aura\Router\Route;
use Pimple\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * TinyFramework
 */
class TinyFrame extends Component
{

    public static $instance;

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
        $this->container['config'] = \array_replace_recursive(array(
            'uriRoot' => $this->getUriRoot(),
            'uriContent' => $this->getUriContent(),
            'dirRoot' => $this->getDirRoot(),
            'dirContent' => $this->getDirContent(),
            'dirTemplates' => $this->getDirRoot() . '/templates',
            'extensions' => array(),
            'template' => 'default',
            'templates' => array(), // templateName to filepath mapping
            'controllerNamespace' => null,
            'onControllerInit' => null,
        ), $container['config']);
        $this->container->register(new ServiceProvider());
        $this->container['errorHandler']->eventManager->subscribe('php.shutdown', function (Event $event) {
            if ($event['error'] && $event['error']['category'] == 'fatal' && !$event['error']['exception']) {
                $error = $event['error'];
                $this->debug->warn('fatal error (non exception)', $error);
                $exception = new ErrorException(
                    $error['message'],
                    500,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );
                $controller = new ExceptionController($this->container);
                $this->container['controller'] = $controller;
                $response = $controller->handleException($exception);
                $response = $this->applyTemplateMiddleware($this->request, $response);
                $this->sendResponse($response);
            }
        }, 1);
        parent::__construct($container);
        foreach ($this->container['config']['extensions'] as $extension) {
            $this->extendWith($extension);
        }
        if ($this->container['config']['onControllerInit']) {
            $this->eventManager->subscribe('tinyFrame.controllerInit', $this->container['config']['onControllerInit']);
        }
        self::$instance = $this;
	}

    /**
     * __get magic method
     *
     * @param string $key property to get
     *
     * @return mixed
     */
    /*
    public function __get($key)
    {
        if ($this->container->offsetExists($key)) {
            return $this->container[$key];
        }
        return null;
    }
    */

    /**
     * [doMethod description]
     *
     * @param Controller $controller Controller instance
     * @param string     $method     method to call
     * @param array      $args       option method arguments.  If not specified, derived from request
     *
     * @return ResponseInterface
     */
    public function doMethod(Controller $controller, $method, $args = array())
    {
        $this->debug->group(__METHOD__, \get_class($controller), $method);
        // $refObj = new \ReflectionObject($controller);
        // $hasMethod = $refObj->hasMethod($method);
        $refMethod = new \ReflectionMethod($controller, $method);
        $argValues = $args ?: $this->getMethodArgValues($refMethod);
        // $this->debug->warn('argValues', $argValues);
        \ob_start();
        // \call_user_func_array(array($this->debug, 'group'), \array_merge(array(\get_class($controller) . '->' . $method), $argValues));
        $return = $refMethod->invokeArgs($controller, $argValues);
        // $this->debug->groupEnd();
        $output = \ob_get_clean();
        if ($return instanceof ResponseInterface) {
            $this->debug->info('action returned ResponseInterface');
        } elseif ($controller->hasResponse()) {
            $this->debug->info('controller hasResponse');
            $return = $controller->response;
        } elseif ($return) {
            $this->debug->info('action returned value');
            $return = $controller->response->withBody($controller->streamify($return));
        } elseif ($output) {
            $this->debug->info('have buffered output');
            $return = $controller->response->withBody($controller->streamify($output));
        } else {
            $this->debug->info($method . ' did not generate a response body');
            $return = $controller->response;
        }
        /*
        if ($method !== 'defaultAction') {
            $this->debug->info('trying defaultAction');
            return $this->doMethod($controller, 'defaultAction');
        }
        */
        $this->debug->groupEnd();
        return $return;
    }

    /**
     * PSR 15 implementation (RequestHandlerInterface)
     *
     * @param ServerRequestInterface $request Rrequest
     *
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request)
    {
        $this->router->buildRoutes($this->routes ?: array());
        foreach ($this->route->attributes as $k => $v) {
            $request = $request->withAttribute($k, $v);
        }
        $this->request = $request;
        // darn singleton
        unset($this->container['request']);
        $this->container['request'] = $request;
        return $this->handleRoute($this->route);
    }

    /**
     * [dispatchRoute description]
     *
     * @param Route $route Route instance
     *
     * @return ResponseInterface
     */
    public function handleRoute(Route $route)
    {
        $this->debug->group(__METHOD__);
        // $this->debug->log('route', $route);
        try {
            if (\is_string($route->handler)) {
                // $this->debug->warn('handler is string');
                $classname = $route->handler;
                if ($classname{0} !== '\\') {
                    $classname = '\\' . $this->container['config']['controllerNamespace'] . '\\' . $classname;
                }
                $this->container['controller'] = new $classname($this->container);
                $this->eventManager->publish('tinyFrame.controllerInit', $this->container['controller']);
                $this->container['controller']->init();
                $extensions = array(
                    'alerts',
                    'head',
                    'router',
                );
                foreach ($extensions as $extension) {
                    $this->container['controller']->extendwith($extension);
                }
            }
            foreach ($route->extras as $k => $v) {
                $this->container['controller']->{$k} = $v;
            }
            // $this->debug->log('container', $this->container);
            $action = $route->attributes['action'];
            // if ($this->container['controller']->hasAction($action)) {
            $method = 'action' . \ucfirst($action);
            if (!\method_exists($this->container['controller'], $method)) {
                $this->debug->warn($method . ' is not defined, using defaultAction');
                $method = 'defaultAction';
            }

            $rules = $this->container['controller']->rules();
            if (isset($rules[$action])) {
                foreach ((array) $rules[$action] as $rule) {
                    $this->debug->warn('rule', $rule);
                    if ($rule == 'authenticated') {
                        if (!$this->user->userlevel) {
                            $this->session['afterLoginUrl'] = (string) $this->request->getUri();
                            throw new RedirectException('/user/login');
                        }
                    }
                }
            }

            $response = $this->doMethod($this->container['controller'], $method);
            $response = $this->applyTemplateMiddleware($this->request, $response);
        } catch (\Exception $e) {
            $this->debug->warn('caught exception', array(
                'exception class' => \get_class($e),
                'message' => $e->getMessage(),
            ), $this->debug->meta(array(
                'file' => $e->getFile(),
                'line' => $e->getLine()
            )));
            $this->container['controller'] = new ExceptionController($this->container);
            $response = $this->doMethod($this->container['controller'], 'handleException', array($e));
            $response = $this->applyTemplateMiddleware($this->request, $response);
        }
        $this->debug->groupEnd();
        return $response;
    }

    /**
     * Apply template to response
     *
     * @param Request           $request  [description]
     * @param ResponseInterface $response [description]
     *
     * @return ResponseInterface new response
     */
    public function applyTemplateMiddleware(Request $request, ResponseInterface $response)
    {
        $this->debug->group(__METHOD__);
        /*
        $template = $this->container['controller']->template;
        if ($template) {
            $this->debug->info('template defined', $template);
        } else {
            $template = $this->container['controller']->getTemplate();
            $templateFile = $this->container['controller']->getTemplateFile();
            $this->debug->log('templateFile', $templateFile);
            if ($templateFile) {
                \ob_start();
                require_once $templateFile;
                $template = \ob_get_clean();
            }
        }
        */
        $template = $this->container['controller']->getTemplate();
        $this->debug->warn('publishing tinyFrame.template');
        // $this->debug->warn('template', $template);
        $event = $this->eventManager->publish(
            'tinyFrame.template',
            $this->container['controller'],
            array(
                'request' => $request,
                'response' => $response,
                'template' => $template,
                'templateName' => strpos($this->template, "\n") === false
                    ? $this->template
                    : null,
            )
        );
        $response = $event['response'];
        $template = $event['template'];
        $controller = $this->container['controller'];
        if ($template) {
            $controller->body = (string) $response->getBody();
            $str = $this->renderer->render($template);
            $this->debug->warn('done rendering template');
        } else {
            $this->debug->log('no template');
            $str = (string) $response->getBody();
            $str = $this->renderer->render($str);
        }
        try {
            if (!$this->renderCompleteException) {
                $event = $this->eventManager->publish(
                    'tinyFrame.renderComplete',
                    $controller,
                    array(
                        'return' => $str,
                        'response' => $response,
                    )
                );
                $response = $event['response'];
                $str = $event['return'];
            }
        } catch (\Exception $e) {
            $this->renderCompleteException = $e;
            throw $e;
        }
        $stream = $controller->streamify($str);
        $response = $response->withBody($stream);
        $this->debug->groupEnd();
        return $response;
    }

	/**
     * Output our page
     *
     * @return void
     */
    public function run()
	{
        $response = $this->handle($this->request);
        $this->sendResponse($response);
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
        $this->debug->info(__METHOD__);
        $response = $this->eventManager->publish('tinyFrame.sendResponse', $this, array(
            'response' => $response,
        ))->getValue('response');
        $debugOutput = $this->debug->output();
        $this->debug->setCfg('output', false);
        $httpLine = \sprintf(
            'HTTP/%s %s %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );
        \header($httpLine, true, $response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                \header($name . ': ' . $value, false);
            }
        }
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        while (!$stream->eof()) {
            echo $stream->read(1024 * 8);
        }
        echo $debugOutput;
    }

    /**
     * Get parameter values to pass to method
     *
     * We first look at request attributes & then request query parameters
     *
     * @param \ReflectionMethod $refMethod reflection method
     *
     * @return mixed[]
     * @throws InvalidArgument
     */
    protected function getMethodArgValues(\ReflectionMethod $refMethod)
    {
        $argValues = array();
        foreach ($refMethod->getParameters() as $param) {
            $name = $param->getName();
            $val = $this->request->getAttribute($name);
            if ($val === null) {
                $val = $this->request->getQueryParam($name);
            }
            if ($val !== null) {
                if ($param->isArray()) {
                    $argValues[] = \is_array($val)
                        ? $val
                        : array($val);
                } else {
                    $argValues[] = $val;
                }
                /*
                } elseif (!\is_array($val)) {
                    $argValues[] = $val;
                } else {
                    throw new InvalidArgument();
                }
                */
            } elseif ($param->isDefaultValueAvailable()) {
                $argValues[] = $param->getDefaultValue();
            } else {
                throw new InvalidArgument();
            }
        }
        return $argValues;
    }

    /**
     * Get site directory
     *
     * @return string
     */
    protected function getDirRoot()
    {
        if (isset($this->container['config']['dirRoot'])) {
            return $this->container['config']['dirRoot'];
        }
        $backtrace = \debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
        $frame = \array_pop($backtrace);
        $dir = \dirname($frame['file']);
        $this->container['config'] += array('dirRoot' => $dir);
        return $dir;
    }

    /**
     * Get root content directory
     *
     * @return string
     */
    protected function getDirContent()
    {
        if (isset($this->container['config']['dirContent'])) {
            return $this->container['config']['dirContent'];
        }
        $dir = $this->getDirRoot() . '/content';
        $this->container['config'] += array('dirConfig' => $dir);
        return $dir;
    }

    /**
     * Get root URI
     *
     * @return string
     */
    protected function getUriRoot()
    {
        if (isset($this->container['config']['uriRoot'])) {
            return $this->container['config']['uriRoot'];
        }
        // ksort($_SERVER);
        // $this->debug->log('_SERVER', $_SERVER);
        /*
            avoid using $_SERVER['SCRIPT_NAME']
            @link https://issues.apache.org/bugzilla/show_bug.cgi?id=40102
        */
        $this->debug->warn('SCRIPT_FILENAME', $_SERVER['SCRIPT_FILENAME']);
        $this->debug->warn('CONTEXT_DOCUMENT_ROOT', $_SERVER['CONTEXT_DOCUMENT_ROOT']);
        if (\strpos($_SERVER['SCRIPT_FILENAME'], $_SERVER['CONTEXT_DOCUMENT_ROOT']) === 0) {
            $uri = \substr($_SERVER['SCRIPT_FILENAME'], \strlen($_SERVER['CONTEXT_DOCUMENT_ROOT']));
            $uri = \dirname($uri);
            // $this->debug->info('uri', $uri);
        } else {
            $uri = \dirname($_SERVER['SCRIPT_NAME']);
            // $this->debug->info('using SCRIPT_NAME', $uri);
        }
        $uri = \rtrim($uri, '/') . '/';
        $this->container['config'] += array('uriRoot' => $uri);
        return $uri;
    }

    /**
     * Get root content /URI
     *
     * @return string
     */
    protected function getUriContent()
    {
        if (isset($this->container['config']['uriContent'])) {
            return $this->container['config']['uriContent'];
        }
        $dirDocRoot = \realpath($_SERVER['DOCUMENT_ROOT']);
        $dirContent = $this->getDirContent();
        $dirRoot = $this->getDirRoot();
        if (\strpos($dirContent, $dirDocRoot) === 0) {
            // $this->debug->log('root is ancestor');
            $uri = \substr($dirContent, \strlen($dirDocRoot));
        } elseif (\strpos($dirContent, $dirRoot) === 0) {
            // $this->debug->log('site is ancestor');
            $relpath = \substr($dirContent, \strlen($dirRoot));
            $uri = \str_replace('//', '/', $this->getUriRoot() . $relpath);
        } else {
            $this->debug->warn('dirContent is outside of DocumentRoot and site directory ¯\_(ツ)_/¯');
            // there's likely a symlink -> unable to resolve
            $uri = $this->getUriRoot();
        }
        // make sure ends in a single /
        $uri = \rtrim($uri, '/') . '/';
        $this->container['config'] += array('uriContent' => $uri);
        return $uri;
    }
}
