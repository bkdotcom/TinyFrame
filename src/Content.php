<?php

namespace bdk\TinyFrame;

use bdk\Net;
use bdk\Php;
use bdk\Str;
use bdk\PubSub\Event;
use bdk\PubSub\SubscriberInterface;

/**
 * Content repository
 *
 * Ties closely with Renderer.. updating a rendered template
 */
class Content extends Component // implements SubscriberInterface    // ExtensionInterface,
{

    public $library = array();
    protected $callableMap = array();

    /**
     * Constructor
     *
     * @param object $controller Controller instance
     */
    /*
    public function __construct($controller)
    {
        $this->debug = $controller->debug;
        $this->controller = $controller;
        return;
    }
    */

    /**
     * add an object that implements content generator (has getContentGenerators method)
     *
     * @param ContentInterface $obj object containing content generating methods
     *
     * @return void
     */
    public function addContentInterface(ContentInterface $obj)
    {
        foreach ($obj->getContentGenerators() as $key => $val) {
            if (!\is_string($key) && \is_string($val)) {
                // derive key from method
                $key = \preg_replace('/^get_?(\w+)$/', '$1', $val);
                $key = Str::toCamelCase($key);
                $key = \lcfirst($key);
            }
            if (!\is_string($key)) {
                continue;
            }
            if ($val instanceof \Closure) {
                $this->addGenerator($key, $val);
            }
            if (\is_string($val) && \method_exists($obj, $val)) {
                $this->addGenerator($key, array($obj, $val));
            }
        }
    }

    /**
     * [addGenerator description]
     *
     * @param string   $key      content key
     * @param callable $callable callable that generates content
     *
     * @return void
     */
    public function addGenerator($key, $callable)
    {
        // $key = $this->keyCamel($key);
        $key = Str::toCamelCase($key);
        $this->callableMap[$key] = $callable;
    }

    /**
     * Get content
     *
     * @param string $key  key of content to fetch
     * @param array  $args arguments to pass to getter
     *
     * @return string|false
     */
    public function get($key, $args = array())
    {
        /*
        var_dump(array(
            'key' => $key,
            'args' => $args,
            'func_args' => func_get_args(),
        ));
        if ($key == 'snippet') {
            exit;
        }
        */
        // $this->debug->log(__METHOD__, $key, $args);
        if ($key{0} == '$') {
            // $this->debug->warn('variable');
            $key = \str_replace('$this->', '$this->controller->', $key);
            // $isset = false;
            $isset = eval('return isset(' . $key . ');');
            if ($isset) {
                return eval('return ' . $key . ';');
            }
        }
        $keys = \array_unique(array(
            // $this->keyCamel($key),
            Str::toCamelCase($key),
            $key,
        ));
        $args = (array) $args;
        foreach ($keys as $key) {
            // $this->debug->log('key', $key);
            $getter = 'get' . \ucfirst($key);
            if (empty($args) && isset($this->library[$key]) && $this->library[$key] !== true) {
                /*
                    check library
                */
                /*
                if (strpos($this->library[$key], 'jqm.autoComplete')) {
                    $this->debug->warn('in library', $this->library[$key]);
                }
                */
                return $this->library[$key];
            }
            if (empty($args)) {
                /*
                    Check controller prop... this will likely call controller's __get()
                */
                $val = $this->controller->{$key};
                if (\is_string($val)) {
                    // $this->debug->warn('controller prop', $val);
                    $this->library[$keys[0]] = $val;
                    return $val;
                }
            }
            if (\method_exists($this->controller, $getter)) {
                // $this->debug->log('controller method exists');
                return \call_user_func_array(array($this->controller, $getter), $args);
            }
            if (isset($this->callableMap[$key])) {
                // $this->debug->log('callable', $key, $args);
                \ob_start();
                $return = \call_user_func_array($this->callableMap[$key], $args);
                return \ob_get_clean() . $return;
            }
            /*
            $props = \get_object_vars($this->controller);
            // $this->debug->log('props', array_keys($props));
            foreach ($props as $prop) {
                if (\is_object($prop) && \method_exists($prop, $getter)) {
                    // $str = $prop->{$getter}();
                    $str = \call_user_func_array(array($prop, $getter), $args);
                    break;
                }
            }
            */
            // $str = $this->callGenerator($key, $args);
            /*
            if (empty($args)) {
                $this->library[$key] = $str;
            }
            if ($str !== false) {
                break;
            }
            */
        }
        // @todo...  check a property... or perhaps template->istokenRendered
        /*
        if (Php::getCallerInfo(false) !== 'bdk\TinyFrame\Renderer::tokenReplace'
            && !($key === 'body' && $noRender)
        ) {
            // $this->debug->info('rendering', $key, $args);
            $str = $this->renderer->render($str);
        }
        */
        /*
        if ($this->resetStatus) {
            // workaround for PHP "Bug" that sends a 500 response when an eval doesn't parse
            \trigger_error('unable to parse args '.\func_get_arg(0));
            $code = !empty($_SERVER['REDIRECT_STATUS'])
                ? $_SERVER['REDIRECT_STATUS']
                : 200;
            \header('Status:', true, $code);
            $this->resetStatus = false;
        }
        */
        // $this->debug->log('str', $str);
        // $this->debug->groupEnd();
        $this->debug->info('no content found for ', $key);
        return false;
    }

    /*
    public static function getContentGenerators()
    {
        return array();
    }
    */

    /**
     * Get subscribed events
     *
     * @return array
     */
    public function getSubscriptions()
    {
        return array(
            'page.extend' => 'onPageExtend',
            'page.output' => 'onPageOutput',
        );
    }

    /**
     * is key already output?
     *
     * @param string $key [description]
     *
     * @return boolean      [description]
     */
    public function isKeyOutput($key)
    {
        return $this->renderer->isTokenRendered($key);
    }

    /**
     * [getKey description]
     *
     * @param string $key [description]
     *
     * @return string
     */
    /*
    public function keyCamel($key)
    {
        if (\strpos($key, '_')) {
            $key = Str::toCamelCase($key);
        }
        return $key;
    }
    */

    /**
     * page.extend handler
     *
     * @param Event $event event object
     *
     * @return void
     */
    /*
    public function onPageExtend(Event $event)
    {
        $this->debug->groupCollapsed(__METHOD__);
        $this->addGeneratorObj($event['extendWith']);
        $this->debug->groupEnd();
    }
    */

    /**
     * Add style tag to page if needed
     *
     * @param Event $event event object
     *
     * @return void
     */
    /*
    public function onPageOutput(Event $event)
    {
        $this->debug->groupCollapsed(__METHOD__);
        // $page = $event->getSubject();
        if (Net::getHeaderValue('Content-Type') == 'text/html') {
            if (!empty($this->library['style']) && !$this->isKeyOutput('style')) {
                $this->debug->warn('adding style token');
                $this->update('body', '<style type="text/css"><!--'."\n".'{{style}}'."\n".'--></style>', 'top');
            }
        }
        $this->debug->groupEnd();
    }
    */

    /**
     * set page object
     *
     * @param object $page page object
     *
     * @return void
     */
    /*
    public function setPage($page)
    {
        $this->page = $page;
    }
    */

    /**
     * Update a content block
     *
     * @param string $key   content key/name
     * @param string $str   content to append
     * @param string $where ='bottom' (replace|before|top|bottom|after)
     *
     * @return void
     */
    public function update($key, $str = '', $where = 'bottom')
    {
        $this->debug->group(__METHOD__, $key, $where);
        $cur = null;
        // $key = $this->keyCamel($key);
        $key = Str::toCamelCase($key);
        $isKeyOutput = $this->isKeyOutput($key);
        // get current value
        $this->debug->log('isKeyOutput', $isKeyOutput);
        if ($isKeyOutput) {
            // $this->debug->info('already output', $key);
            $cur = $this->renderer->renderedTokenGet($key);
            // $this->debug->log('cur', $cur);
            // $lenOrig = strlen($cur);
        } elseif (isset($this->library[$key]) && $this->library[$key] !== true) {
            // $this->debug->log('not output, but in library', $key);
            $cur = $this->library[$key];
            // $lenOrig = 0;
        }
        if ($cur !== null) {
            // $this->debug->warn('updateStrNew');
            /*
            if (\in_array($where, array('replace','top')) && \preg_match($GLOBALS['templater']['re_template'], $str, $matches, PREG_OFFSET_CAPTURE) && $matches[0][1] == 0) {
                $this->debug->warn('token with 0 offset', $str);
                $str = ' '.$str;
            }
            */
            $str = $this->updateStrNew($str, $cur, $where);
        }
        if ($isKeyOutput) {
            // $this->debug->warn($key, $str, 'replace');
            $this->renderer->renderedTokenUpdate($key, $str, 'replace');
        } else {
            $this->library[$key] = $str;
        }
        // $this->debug->log('library', $this->library);
        $this->debug->groupEnd();
        return;
    }

    /**
     * [callGenerator description]
     *
     * @param array $key  name/key/function
     * @param array $args arguments to pass
     *
     * @return string|false
     */
    /*
    protected function callGenerator($key, $args)
    {
        $return = false;
        if (\function_exists('get_'.$key)) {
            // $this->debug->log('function exists');
            \ob_start();
            $return = \call_user_func_array('get_'.$key, $args);
            $return = \ob_get_contents().$return;
            \ob_end_clean();
        } elseif (\function_exists('get'.\ucfirst($key))) {
            \ob_start();
            $return = \call_user_func_array('get'.\ucfirst($key), $args);
            $return = \ob_get_contents().$return;
            \ob_end_clean();
        } elseif (isset($this->callableMap[$key]) && \is_callable($this->callableMap[$key])) {
            // $this->debug->log('callable', $key);
            \ob_start();
            $return = \call_user_func_array($this->callableMap[$key], $args);
            $return = \ob_get_contents().$return;
            \ob_end_clean();
        } else {
            // $this->debug->log('callableMap', $this->callableMap);
        }
        return $return;
    }
    */

    /**
     * [getGenerators description]
     *
     * @param object $obj object
     *
     * @return array
     */
    /*
    protected function getGenerators($obj)
    {
        $generators = array();
        if (\method_exists($obj, 'getContentGenerators')) {
            foreach ($obj->getContentGenerators() as $key => $methodName) {
                if (!\method_exists($obj, $methodName)) {
                    continue;
                }
                if (!\is_string($key)) {
                    if (\preg_match('/get([A-Z][a-zA-Z]+)/', $methodName, $matches)) {
                        $key = \lcfirst($matches[1]);
                    } else {
                        $key = $methodName;
                    }
                }
                // $this->addGenerator($key, array($obj, $methodName));
                $generators[$key] = array($obj, $methodName);
            }
        } else {
            foreach (\get_class_methods($obj) as $methodName) {
                if (\in_array($methodName, array('getActionHandlers','getSubscriptions'))) {
                    continue;
                }
                if (\preg_match('/get([A-Z][a-zA-Z]+)/', $methodName, $matches)) {
                    $key = \lcfirst($matches[1]);
                    // $this->addGenerator($key, array($obj, $methodName));
                    $generators[$key] = array($obj, $methodName);
                }
            }
        }
        return $generators;
    }
    */

    /**
     * updates passed params
     *
     * @param string $key  key to parse
     * @param array  $args arguments parsed from key
     *
     * @return void
     */
    /*
    protected function keyParse(&$key, &$args)
    {
        $regex = '/^'
            .'(\S+?)'
            .'(?:'
                .'\((.+)\)'
                .'|\s+((?:[\'"\$]|array|true|false|null).*)'
            .'$)/s';
        // $this->debug->log('keyParse', $key);
        if (\preg_match($regex, $key, $matches)) {
            // $this->debug->info('func()', $matches);
            $key = $matches[1];
            $args = !empty($matches[2])
                ? $matches[2]
                : $matches[3];
            $parsed = @eval('$args = array('.$args.');');   // eval will return null or false
            // if ($parsed === false) {
                // $this->resetStatus = true;
            // }
        } else {
            // $this->debug->info('unquoted string arg');
            $args = \explode(' ', $key, 2);
            $key = \array_shift($args);
        }
        // $this->debug->log('key', $key);
        // $this->debug->log('args', $args);
    }
    */

    /**
     * [updateStrNew description]
     *
     * @param string $str   string to append
     * @param string $cur   current string
     * @param string $where = 'bottom' (replace|before|top|bottom|after)
     *
     * @return string
     */
    protected function updateStrNew($str, $cur, $where)
    {
        if ($where == 'replace') {
            // $insLen = strlen($str) - $lenOrig;
        } elseif (\in_array($where, array('bottom','after'))) {
            $nlCur = \preg_match('/[\r\n]\s*$/', $cur);
            $nlIns = \preg_match('/^\s*[\r\n]/', $str);
            if (!$nlCur && !$nlIns) {
                $str = "\n" . $str;
            }
            // $insLen = strlen($str);
            $str = $cur . $str;
        } else {
            $nlCur = \preg_match('/^\s*[\r\n]/', $cur);
            $nlIns = \preg_match('/[\r\n]\s*$/', $str);
            if (!$nlCur && !$nlIns) {
                $str = $str . "\n";
            }
            // $insLen = strlen($str);
            $str = $str . $cur;
        }
        return $str;
    }
}
