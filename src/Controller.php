<?php

namespace bdk\TinyFrame;

use Pimple\Container;
use bdk\TinyFrame\Component;
use bdk\TinyFrame\Exception\ExitException;
use bdk\TinyFrame\Exception\HttpException;
use bdk\TinyFrame\Exception\RedirectException;

/**
 * Base controller class
 */
class Controller extends Component
{

    // protected $actions = array();
    public $bodyAttribs = array();
    public $template = 'default';

    /**
     * Output message & data json_encoded along with content-type header
     *
     * @param string $message error message
     * @param array  $data    optional data to include in response
     * @param array  $headers optional additional headers
     *
     * @return void
     * @throws ExitException
     */
    public function ajaxError($message = '', $data = array(), $headers = array())
    {
        $json = \json_encode(\array_merge(
            array(
                'success' => false,
                'message' => $message
            ),
            $data
        ));
        $this->template = null;
        if (isset($_GET['callback'])) {
            $stream = $this->streamify($_GET['callback'] . '(' . $json . ')');
            $this->container['response'] = $this->response
                ->withBody($stream)
                ->withHeader('Content-type', 'application/javascript');
        } else {
            $stream = $this->streamify($json);
            $this->container['response'] = $this->response
                ->withBody($stream)
                ->withHeader('Content-type', 'application/json');
        }
        foreach ($headers as $k => $v) {
            $this->container['response'] = $this->response
                ->withHeader($k, $v);
        }
        throw new ExitException('ajaxError');
    }

    /**
     * Output data json_encoded along with content-type header
     *
     * @param array $data    optional data to include in response
     * @param array $headers optional header values
     *
     * @return void
     * @throws ExitException
     */
    public function ajaxSuccess($data = array(), $headers = array())
    {
        $json = \json_encode(
            array(
                'success' => true,
            ) + $data
        );
        $this->template = null;
        if (isset($_GET['callback'])) {
            $stream = $this->streamify($_GET['callback'] . '(' . $json . ')');
            $this->container['response'] = $this->response
                ->withBody($stream)
                ->withHeader('Content-type', 'application/javascript');
        } else {
            $stream = $this->streamify($json);
            $this->container['response'] = $this->response
                ->withBody($stream)
                ->withHeader('Content-type', 'application/json');
        }
        foreach ($headers as $k => $v) {
            $this->container['response'] = $this->response
                ->withHeader($k, $v);
        }
        throw new ExitException('ajaxSuccess');
    }

    /**
     * Default/fallback action
     *
     * @return void
     * @throws HttpException
     */
    public function defaultAction()
    {
        $this->debug->info('filepath', $this->filepath, $this->debug->meta('detectFiles'));
        if (!$this->filepath) {
            throw new HttpException('404');
        }
        require $this->filepath;
    }

    public function getBodyAttribs()
    {
        $this->debug->log('bodyAttribs', $this->bodyAttribs);
        $attribs = $this->debug->utility->arrayMergeDeep(array(
            'class' => array( $this->getPageClass() ),
        ), $this->bodyAttribs);
        return $this->debug->html->buildAttribString($attribs);
    }

    /**
     * Return debug output
     *
     * @return string
     */
    public function getDebug()
    {
        $data = array(
            'groupStacks' => $this->debug->getData('groupStacks'),
            'groupPriorityStack' => $this->debug->getData('groupPriorityStack'),
        );
        $return = $this->debug->output();
        $this->debug->setData($data);
        return $return;
    }

    /**
     * Get content filepath for current route
     *
     * @return string
     */
    public function getFilepath()
    {
        $this->filepath = $this->router->defaultFilepath($this->route);
        return $this->filepath;
    }

    /**
     * Get unique css classname
     *
     * @return string
     */
    public function getPageClass()
    {
        $str = '';
        $path = array();
        $uriPath = \explode('/', $this->request->getUri()->getPath());
        $uriPath = \array_filter($uriPath, 'strlen');
        $uriPath = \array_values($uriPath);
        // $this->debug->warn('uriPath', $uriPath);
        foreach ($uriPath as $k => $v) {
            if ($v === 'index') {
                continue;
            }
            $v = \strtolower($v);
            $v = \preg_replace('/\W/', '', $v);
            if ($k) {
                $v = \ucfirst($v);
            }
            $path[] = $v;
        }
        return \implode('', $path);

        /*
        if (!empty($this->actions->history)) {
            $action = \end($this->actions->history);
            $this->debug->log('last action', $action);
            $action = \str_replace('_', ' ', $action);
            $action = \strtolower($action);
            $action = \ucwords($action);
            $action = \str_replace(' ', '', $action);
            $actionHsc = \htmlspecialchars($action);
            if ($actionHsc == $action) {
                $str .= ' action'.$action;
            }
        }
        */
        // $str .= ' ts'.$_SERVER['REQUEST_TIME'];
        return $str;
    }

    /**
     * Get current url with new attributes/params
     *
     * @param array $params params + attributes
     * @param array $opts   options
     *
     * @return string
     */
    public function getSelfUrl($params = array(), $opts = array())
    {
        $params = \array_merge(
            $this->route->attributes,
            $this->request->getQueryParams(),
            $params
        );
        return $this->router->getUrl($this->route->name, $params, $opts);
    }

    /**
     * Get template string
     *
     * @return string
     */
    public function getTemplate()
    {
        if (!$this->template) {
            return '';
        }
        if (\strpos($this->template, "\n") !== false) {
            return $this->template;
        }
        $template = '';
        $templateFile = $this->getTemplateFile();
        if ($templateFile) {
            \ob_start();
            require_once $templateFile;
            $template = \ob_get_clean();
        }
        return $template;
    }

    /**
     * Find template filepath
     *
     * @return string|false filepath
     */
    public function getTemplateFile()
    {
        $this->debug->info(__METHOD__);
        if (!$this->template) {
            return null;
        }
        $template = $this->template;
        if (isset($this->container['config']['templates'][$template])) {
            // $this->debug->info('template defined in templates');
            return $this->container['config']['templates'][$template];
        }
        if (\is_file($template)) {
            return $template;
        }
        $directories = array(
            $this->container['config']['dirTemplates'],
        );
        foreach ($directories as $dir) {
            if (!$dir) {
                continue;
            }
            $filepaths = array(
                $dir . DIRECTORY_SEPARATOR . $template . '.php',
                $dir . DIRECTORY_SEPARATOR . $template . '.html',
            );
            foreach ($filepaths as $filepath) {
                // $this->debug->log('filepath', $filepath);
                if (\is_file($filepath)) {
                    return $filepath;
                }
            }
        }
        $this->debug->warn('template file not found');
        if ($template !== 'default') {
            $this->template = 'default';
            return $this->getTemplateFile();
        }
    }

    /**
     * Output reponse to an exception
     *
     * @param \Exception $e Exception
     *
     * @return Response
     */
    /*
    public function handleException(\Exception $e)
    {
        $this->exception = $e;
        if ($e instanceof ExitException) {
            $this->debug->info('ExitException');
            return $this->response;
        } elseif ($e instanceof RedirectException) {
            $this->debug->info('RedirectException');
            $event = $this->eventManager->publish(
                'tinyFrame.exception',
                $this,
                array(
                    'exception' => $e,
                    'response' => $this->response
                        ->withStatus($e->getCode(), $e->getMessage())
                        ->withHeader('Location', $e->getUrl()),
                )
            );
            return $event['response'];
        } elseif ($e instanceof HttpException) {
            $this->debug->info('HttpException');
            $event = $this->eventManager->publish(
                'tinyFrame.exception',
                $this,
                array(
                    'exception' => $e,
                    'response' => $this->response
                        ->withStatus($e->getCode(), $e->getMessage())
                        ->withBody($this->streamify($this->getFilepath())),
                )
            );
            return $event['response'];
        } else {
            $this->template = 'error';
            $event = $this->eventManager->publish(
                'tinyFrame.exception',
                $this,
                array(
                    'exception' => $e,
                    'response' => $this->response
                        ->withStatus('500')
                        ->withBody($this->streamify($this->getFilepath())),
                )
            );
            return $event['response'];
        }
    }
    */

    /**
     * Test if response has body
     *
     * @return boolean
     */
    public function hasResponse()
    {
        $hasResponse = false;
        $stream = $this->response->getBody();
        // $this->debug->log('stream metadata', $stream->getMetadata());
        $stream->rewind();
        // $this->debug->log('rewound');
        if ($stream->read(1) !== '') {
            $hasResponse = true;
        }
        return $hasResponse;
    }

    /*
    public function hasAction($action)
    {
        $method = 'action' . \ucfirst($action);
        if (\method_exists($this, $method)) {
            return true;
        }
        return isset($this->actions[$method]);
    }

    public function getActions()
    {
        $actions = $this->actions;
        $methods = \get_class_methods($this);
        foreach ($methods as $method) {
            if (\strpos($method, 'action') === 0) {
                $actions[$method] = $this;
            }
        }
        return $actions;
    }
    */

    public function init()
    {
        foreach ($this->getExtensions() as $extension) {
            $this->extendWith($extension);
        }
    }

    /**
     * Redirect to specified url
     *
     * @param string $url new url
     *
     * @return void
     * @throws RedirectException
     */
    public function redirect($url = null)
    {
        $this->debug->info(__METHOD__, $url);
        $this->session['alerts'] = $this->alerts->getAll();
        throw new RedirectException($url);
    }

    public function rules()
    {
        return array();
    }

    /**
     * Wrapper for \GuzzleHttp\Psr7\stream_for()
     * Additonally recognizes and converts a filepath
     *
     * @param mixed $val value to convert to stream
     *
     * @return Stream
     */
    public function streamify($val)
    {
        if (\is_string($val) && \strpos($val, "\n") === false && \is_file($val)) {
            if (\substr($val, -4) == '.php') {
                \ob_start();
                require $val;
                $val = \ob_get_clean();
            } else {
                $val = \fopen($val, 'r');
            }
        }
        return \GuzzleHttp\Psr7\stream_for($val);
    }
}
