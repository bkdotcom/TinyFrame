<?php

namespace bdk\TinyFrame;

use Pimple\Container;
use bdk\PubSub\SubscriberInterface;

/**
 *
 */
class Component
{

    public $container;
    protected $extensions = array();

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
     * Magic method
     *
     * @param string $method method name
     * @param array  $args   arguments
     *
     * @return mixed
     */
    public function __call($method, $args)
    {
        /*
        foreach ($this->extensions as $obj) {
            // $this->debug->log('extension', get_class($obj));
            if (\method_exists($obj, $method)) {
                return \call_user_func_array(array($obj, $method), $args);
            }
        }
        */
        $event = $this->eventManager->publish('tinyFrame.call', $this, array(
            'method' => $method,
            'args' => $args,
        ));
        if ($event->isPropagationStopped()) {
            return $event['return'];
        } else {
            throw new \BadMethodCallException($method . ' is not callable');
        }
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
            // $this->container['debug']->log('__get key', $key);
            $val = $this->container[$key];
            /*
            if (\is_object($val)) {
                $this->{$key} = $val;
            }
            */
            return $val;
        }
        $getter = 'get' . \ucfirst($key);
        if (\method_exists($this, $getter)) {
            return $this->{$getter}();
        }
        if (\property_exists($this, $key)) {
            return $this->{$key};
        }
        /*
        $props = \get_object_vars($this);
        foreach ($props as $prop) {
            if (\is_object($prop) && \method_exists($prop, $getter)) {
                return $prop->{$getter}();
            }
        }
        */
        /*
        foreach ($this->extensions as $obj) {
            if (\method_exists($obj, $getter)) {
                return $obj->{$getter}();
            }
        }
        */
        if (isset($this->container['config'][$key])) {
            return $this->container['config'][$key];
        }
        $val = null;
        return $val;
    }

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
        $getter = 'get' . \ucfirst($key);
        if (\method_exists($this, $getter)) {
            return true;
        }
        if (isset($this->{$key})) {
            return true;
        }
        /*
        $props = \get_object_vars($this);
        foreach ($props as $prop) {
            if (\is_object($prop) && \method_exists($prop, $getter)) {
                return $prop->{$getter}();
            }
        }
        */
        /*
        foreach ($this->extensions as $obj) {
            if (\method_exists($obj, $getter)) {
                return true;
            }
        }
        */
        if (isset($this->container['config'][$key])) {
            return true;
        }
        return false;
    }

    /**
     * Magic Method
     *
     * @param string $key property name
     * @param mixed  $val value
     */
    public function __set($key, $val)
    {
        if ($this->container->offsetExists($key)) {
            unset($this->container[$key]);
            $this->container[$key] = $val;
        } else {
            $this->{$key} = $val;
        }
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
     * [extendWith description]
     *
     * @param object $obj [description]
     *
     * @return void
     * @throws InvalidArgumentException
     */
    public function extendWith($obj)
    {
        // if (\is_string($obj) && $this->container->offsetExists($obj)) {
        if (\is_string($obj) && isset($this->{$obj})) {
            $obj = $this->{$obj};
        } elseif (\is_string($obj) && \class_exists($obj)) {
            $obj = new $obj($this->container);
        } elseif (\is_callable($obj)) {
            $obj = \call_user_func($obj, $this->container);
        }
        if (!\is_object($obj)) {
            throw new \InvalidArgumentException('object, container key, or callable expected');
        }
        // $this->debug->warn(__METHOD__, get_class($obj));
        if ($obj instanceof SubscriberInterface) {
            $this->eventManager->addSubscriberInterface($obj);
        }
        if ($obj instanceof ContentInterface) {
            $this->content->addContentInterface($obj);
        }
        /*
        if ($this instanceof Controller && $obj instanceof ActionInterface) {
            $actions = $obj->getActions($this);
            foreach ($actions as $action) {
                $this->actions[$action] = $obj;
            }
        }
        */
        /*
        if ($obj instanceof ExtensionInterface) {
            if (\method_exists($obj, 'setPage')) {
                $obj->setPage($this);
            }
            if ($this->fullObj) {
                // $this->debug->warn('extendWith!!!');
                $this->eventManager->publish('page.extend', $this, array('extendWith'=>$obj));
                // $this->content->addGeneratorObj($obj);
                // $this->actions->addHandlerObj($obj);
            }
        }
        */
        $this->extensions[] = $obj;
    }

    /**
     * Return's curreently registereed extensions
     *
     * @return array
     */
    public function getExtensions()
    {
        return $this->extensions;
    }
}
