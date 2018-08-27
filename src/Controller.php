<?php

namespace bdk\TinyFrame;

use Pimple\Container;
use bdk\TinyFrame\Exception\ExitException;

class Controller
{

	public $container;

	/**
	 * Constructor
	 *
	 * @param Container $container container instance
	 */
	public function __construct(Container $container)
	{
		$this->container = $container;
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
            $val = $this->container[$key];
            if (\is_object($val)) {
                $this->{$key} = $val;
            }
            return $val;
        }
        /*
        if (\array_key_exists($key, $this->properties)) {
            return $this->properties[$key];
        }
        */
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
        if (isset($this->container['config'][$key])) {
            return $this->container['config'][$key];
        }
        $val = null;
        return $val;
    }

    /*
    public function __set($key, $val)
    {
        if ($this->container->offsetExists($key)) {
            // probably "frozen"
            unset($this->container[$key]);
            $this->container[$key] = $val;
        } else {
            $this->{$key} = $val;
        }
    }
    */

    /**
     * Magic method
     *
     * @param string $key key to check
     *
     * @return boolean
     */
    public function __isset($key)
    {
        if ($this->container->offsetExists($key)) {
            $val = $this->container[$key];
            return !\is_null($val);
        }
        /*
        if (\array_key_exists($key, $this->properties)) {
            return !\is_null($this->properties[$key]);
        }
        */
        $getter = 'get'.\ucfirst($key);
        if (\method_exists($this, $getter)) {
            // return !\is_null($this->{$getter}());
            return true;
        }
        if (isset($this->container['config'][$key])) {
            return true;
        }
        return false;
    }

    /**
     * Output message & data json_encoded along with content-type header
     *
     * @param string $message error message
     * @param array  $data    data to include in response
     *
     * @return void
     * @throws ExitException
     */
    public function ajaxError($message = '', $data = array())
    {
        $json = \json_encode(\array_merge(
            array(
                'success' => false,
                'message' => $message
            ),
            $data
        ));
        if (isset($_GET['callback'])) {
            $stream = \GuzzleHttp\Psr7\stream_for($_GET['callback'] . '(' . $json . ')');
            $this->response = $this->response
                ->withBody($stream)
                ->withHeader('Content-type', 'application/javascript');
        } else {
            $stream = \GuzzleHttp\Psr7\stream_for($json);
            $this->response = $this->response
                ->withBody($stream)
                ->withHeader('Content-type', 'application/json');
        }
        throw new ExitException();
    }

    /**
     * Output data json_encoded along with content-type header
     *
     * @param array $data data to include in response
     *
     * @return void
     * @throws ExitException
     */
    public function ajaxSuccess($data = array())
    {
        $json = \json_encode(
            array(
                'success' => true,
            ) + $data
        );
        if (isset($_GET['callback'])) {
            $stream = \GuzzleHttp\Psr7\stream_for($_GET['callback'] . '(' . $json . ')');
            $this->response = $this->response
                ->withBody($stream)
                ->withHeader('Content-type', 'application/javascript');
        } else {
            $stream = \GuzzleHttp\Psr7\stream_for($json);
            $this->response = $this->response
                ->withBody($stream)
                ->withHeader('Content-type', 'application/json');
        }
        throw new ExitException();
    }

    /**
     * Default/fallback action
     *
     * @return string
     */
    public function defaultAction()
    {
        \ob_start();
        require $this->filepath;
        $this->body = \ob_get_clean();
        $template = \file_get_contents($this->config['template']);
        return $this->renderer->render($template);
    }

    /**
     * Return debug output
     *
     * @return string
     */
    public function getDebug()
    {
        return $this->debug->output();
    }
}
