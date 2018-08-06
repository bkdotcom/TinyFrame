<?php

namespace bdk\TinyFrame;

/**
 * Template engine
 */
class Renderer
{

    // use Utilities;

    public $regex = '@\\\\?(?:
        \{\{\s*(.*?)\s*\}\}|           # {{a}}
        \[::\s*(.*?)\s*::\]|           # [::a::]
        <::\s*(.*?)\s*::>|             # <::a::>
        <!--::\s*+(.*?)\s*+::-->|      # <!--::a::-->
        \/\*::\s*(.*?)\s*::\*\/        # /*::a::*/
        )@sx';
    public $regexGroups = array(1,2,3,4,5);
    // protected $debug;
    // protected $page;
    // protected $templates = array(); // key/value
    protected $updateOffsets = false;
    protected $offsets = array();
    protected $foundToken = false;
    protected $foundEscaped = false;
    protected $posStack = array(); // current position in template
    protected $pos;
    protected $resetStatus = false;
    protected $strStack = array();
    protected $strOut = '';

    /**
     * Constructor
     */
    public function __construct($controller)
    {
        $this->controller = $controller;
        $this->debug = $this->controller->debug;
        return;
    }

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
     * [defineTemplate description]
     *
     * @param mixed  $name  template name/key or array of name => value pairs
     * @param string $value filepath to template or template string
     *
     * @return void
     */
    /*
    public function defineTemplate($name, $value = '')
    {
        if (is_array($name)) {
            foreach ($name as $name2 => $value) {
                $this->templates[$name2] = $value;
            }
        } else {
            $this->templates[$name] = $value;
        }
    }
    */

    /**
     * [getOutput description]
     *
     * @return [type] [description]
     */
    public function getOutput()
    {
        return $this->strOut;
    }

    /**
     * get template string
     *
     * @param string $template template key, filename, or string
     *
     * @return string
     */
    /*
    public function getTemplate($template)
    {
        $this->debug->groupCollapsed(__METHOD__);
        // $this->debug->warn('templates', $this->templates);
        if (!empty($template)) {
            if (isset($this->templates[$template])) {
                // $this->debug->log('Template is defined');   // may be a filepath or a string
                $template = $this->templates[$template];
            }
            if (strpos($template, "\n") !== false || preg_match($this->regex, $template)) {
                $this->debug->log('Template is a string');
            } elseif ($str = $this->fileInclude($template, $this->page)) {
                $this->debug->log('Template is a file');
                $template = $str;
            } elseif (isset($this->templates['default']) && $str = $this->fileInclude($this->templates['default'], $this->page)) {
                $this->debug->log('using default');
                $template = $str;
            } else {
                $this->debug->info('empty template');
                $template = '{{body}}';
            }
        } else {
            $template = '{{body}}';
        }
        $this->debug->groupEnd();
        return $template;
    }
    */

    /**
     * has given content key already been output in template?
     *
     * @param string $key content key
     *
     * @return boolean
     */
    public function isTokenRendered($key)
    {
        /*
        if (!isset($this->offsets[$key])) {
            $this->debug->log($key, $this->offsets);
        }
        */
        return isset($this->offsets[$key]);
    }

    /**
     * replace tokens in template
     *
     * @param string $template template to process
     * @param array  $content  optional content
     *
     * @return string
     */
    public function render($template = null)
    {
        $this->debug->groupCollapsed(__METHOD__, $this->debug->meta('hideIfEmpty'));
        /*
        if (!empty($content)) {
            foreach ($content as $key => $val) {
                $this->page->content->update($key, $val, 'replace');
            }
        }
        */
        if ($template !== null) {
            $this->strStack[] = $template;
            $this->strOut = &$this->strStack[\count($this->strStack)-1];
        } else {
            $this->strOut = &$this->strStack[0];
        }
        $updateOffsetsWas = $this->updateOffsets;
        $this->updateOffsets = !isset($template);
        // $this->debug->log('updateOffsets', $this->updateOffsets);
        do {
            $found = $this->tokensReplace();
        } while ($found);
        $this->updateOffsets = $updateOffsetsWas;
        $strOut = $this->strOut;
        if ($template !== null) {
            \array_pop($this->strStack);
            if ($this->strStack) {
                $this->strOut = &$this->strStack[\count($this->strStack)-1];
            } else {
                $this->strOut = '';
            }
        }
        // $this->debug->info('strOut', $strOut);
        $this->debug->groupEnd();
        return $strOut;
    }

    /**
     * [getRenderedToken description]
     *
     * @param string $key content key
     *
     * @return string
     */
    public function renderedTokenGet($key)
    {
        // $this->debug->groupCollapsed(__CLASS__.'->'.__FUNCTION__, $key);
        $return = '';
        if (isset($this->offsets[$key])) {
            list($keyPos, $len) = $this->offsets[$key];
            $return = \substr($this->strStack[0], $keyPos, $len);
        }
        // $this->debug->groupEnd();
        return $return;
    }

    /**
     * [renderedTokenUpdate description]
     *
     * @param string $key   key we're updating
     * @param string $str   str to insert
     * @param string $where replace|top|bottom|before|after
     *
     * @return void
     */
    public function renderedTokenUpdate($key, $str, $where)
    {
        // $this->debug->groupUncollapse();
        list($pos, $lenOrig) = $this->offsets[$key];
        /*
        $this->debug->warn('renderedTokenUpdate', array(
            'key' => $key,
            'pos' => $pos,
            // 'lenOrig' => $lenOrig,
        ));
        */
        $lenDelta = \strlen($str) - $lenOrig;
        $this->updateOffsets($key, $lenDelta, $where);
        if (\in_array($where, array('replace','top','bottom'))) {
            $pre    = \substr($this->strStack[0], 0, $pos);
            $post   = \substr($this->strStack[0], $pos + $lenOrig);
            $this->strStack[0] = $pre.$str.$post;
        } else {
            $pre    = \substr($this->strStack[0], 0, $pos);
            $post   = \substr($this->strStack[0], $pos + $lenOrig);
            $this->strStack[0] = $pre.$str.$post;
        }
        // $this->debug->log('strOut after '.$key, str_replace(array("\n","\r",' '), array('^','^','*'), $this->strOut));
    }

    /**
     * [setTemplate description]
     *
     * @param string  $template template key, filename, or string
     * @param boolean $replace  whether to replace a previously set template
     *
     * @return void
     */
    public function setTemplate($template, $replace = true)
    {
        // $this->debug->groupCollapsed(__CLASS__.'->'.__FUNCTION__);
        if ($replace || empty($this->strStack[0])) {
            $this->strStack[] = $this->getTemplate($template);
            $this->offsets = array();
        }
        // $this->debug->groupEnd();
    }

    /**
     * [tokensReplace description]
     *
     * @return boolean were tokens found?
     */
    protected function tokensReplace()
    {
        // $this->debug->groupCollapsed(__CLASS__.'->'.__FUNCTION__);
        $strPos = 0;    // position in strIn
        $this->foundToken = false;
        $this->foundEscaped = false;
        $this->posStack[] = 0;
        $this->pos = &$this->posStack[\count($this->posStack)-1];
        while (\preg_match($this->regex, $this->strOut, $matches, PREG_OFFSET_CAPTURE, $strPos)) {
            // $this->debug->log('matches', $matches);
            $token = $matches[0][0];
            $this->pos = $matches[0][1];
            // $this->debug->info('strPos', $strPos, $token);
            $tokenInner = '';
            foreach ($this->regexGroups as $i) {
                if (!empty($matches[$i][0])) {
                    $tokenInner = \trim($matches[$i][0]);
                    break;
                }
            }
            $isEscaped = $token{0} === '\\';
            if ($isEscaped) {
                $this->foundEscaped = true;
                $strPos = $this->pos + \strlen($token);
            } else {
                // $replacement = $this->tokenReplace($token, $tokenInner, $tokenPos);
                $this->foundToken = true;
                $replacement = $this->get($tokenInner); // , $os+$strOutLen, strlen($a[0])
                if (is_object($replacement)) {
                    $this->debug->log('replacement', $replacement);
                }
                $updateOffsetParams = $this->getUpdateOffsetParams($token, $tokenInner, \strlen($replacement));
                if ($updateOffsetParams) {
                    $this->updateOffsets($updateOffsetParams[0], $updateOffsetParams[1]);
                }
                $this->strOut = \substr_replace($this->strOut, $replacement, $this->pos, \strlen($token));
                $strPos = $this->pos + \strlen($replacement);
            }
        }
        \array_pop($this->posStack);
        if ($this->posStack) {
            $this->pos = &$this->posStack[\count($this->posStack)-1];
        }
        return $this->foundToken;
    }

    /**
     * get content
     *
     * @param string $key key of content to fetch
     *
     * @return string
     */
    public function get($key, $args = array())
    {
        $this->debug->groupCollapsed(__METHOD__, $key);
        $argsPassed = \func_get_args();
        // $this->debug->info('argsPassed', $argsPassed);
        $str = false;
        $this->keyParse($key, $args);
        $keys = \array_unique(array(
            $this->keyCamel($key),
            $key,
        ));
        if ($key{0} == '$' && \count($keys) > 1) {
            // don't mess with vars
            \array_shift($keys);
        }
        if (\count($argsPassed) > 1) {
            $args = \array_slice($argsPassed, 1);
        }
        $noRender = isset($args[0]) && $args[0] === 'noRender';
        if ($noRender) {
            \array_shift($args);
        }
        foreach ($keys as $key) {
            $this->debug->log('key', $key);
            $getter = 'get'.\ucfirst($key);
            if (empty($args) && isset($this->controller->{$key}) && $this->controller->{$key} !== true) {
                $this->debug->log('controller->$'.$key.' exists');
                $val = $this->controller->{$key};
                if (!\is_array($val) && (!\is_object($val) || \method_exists($val, '__toString'))) {
                    $this->debug->groupEnd();
                    return $val;
                }
            }
            if ($key{0} == '$') {
                $this->debug->warn('variable');
                $key = \str_replace('$this->', '$this->controller->', $key);
                // $isset = false;
                // $evalReturn = eval('$isset = isset('.$key.');');
                $isset = isset($$key);
                if ($isset) {
                    // $evalReturn = eval('$str = '.$key.';');
                    $str = $$key;
                    $this->debug->log('str', $str);
                }
                /*
                if ($evalReturn === false) {
                    $this->resetStatus = true;
                }
                */
            } elseif (\method_exists($this->controller, $getter)) {
                $this->debug->log('controller method exists');
                $str = \call_user_func_array(array($this->controller, $getter), $args);
                /*
                if (empty($args)) {
                    $this->library[$key] = $str;
                }
                */
            } else {
                $props = \get_object_vars($this->controller);
                // $this->debug->log('props', array_keys($props));
                foreach ($props as $prop) {
                    if (\is_object($prop) && \method_exists($prop, $getter)) {
                        $str = $prop->{$getter}();
                    }
                }
            }
            if ($str !== false) {
                break;
            }
        }
        // @todo...  check a property... or perhaps template->istokenRendered
        /*
        if (Php::getCallerInfo(false) !== 'bdk\Pager\Template::tokenReplace'
            && !($key === 'body' && $noRender)
        ) {
            // $this->debug->info('rendering', $key, $args);
            $str = $this->page->template->render($str);
        }
        */
        if ($this->resetStatus) {
            // workaround for PHP "Bug" that sends a 500 response when an eval doesn't parse
            \trigger_error('unable to parse args '.\func_get_arg(0));
            $code = !empty($_SERVER['REDIRECT_STATUS'])
                ? $_SERVER['REDIRECT_STATUS']
                : 200;
            \header('Status:', true, $code);
            $this->resetStatus = false;
        }
        $this->debug->log('str', $str);
        $this->debug->groupEnd();
        return $str;
    }

    /**
     * [getKey description]
     *
     * @param string $key [description]
     *
     * @return string
     */
    public function keyCamel($key)
    {
        if (\strpos($key, '_')) {
            $key = Str::toCamelCase($key);
        }
        return $key;
    }

    /**
     * updates passed params
     *
     * @param string $key  key to parse
     * @param array  $args arguments parsed from key
     *
     * @return void
     */
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
            if ($parsed === false) {
                $this->resetStatus = true;
            }
        } else {
            // $this->debug->info('unquoted string arg');
            $args = \explode(' ', $key, 2);
            $key = \array_shift($args);
        }
        // $this->debug->log('key', $key);
        // $this->debug->log('args', $args);
    }


    protected function getUpdateOffsetParams($token, $tokenInner, $strlenRep)
    {
        $args = array();
        if ($this->updateOffsets) {
            // $pos = strlen($this->strOut);
            $storeOffsets = \preg_match('/^(\w+)|(tags_?script (true|false))$/i', $tokenInner);
            if ($storeOffsets) {
                $key = $this->page->content->keyCamel($tokenInner);
                // $this->debug->log('key', $key);
                if (!isset($this->offsets[$key])) {
                    // no existing offset
                    $this->offsets[$key] = array($this->pos, 0);
                    if (\count($this->posStack) > 1) {
                        // inserting content
                        // $this->updateOffsets($key, $strlenRep - strlen($token));
                        $args = array($key, $strlenRep - \strlen($token));
                    } else {
                        // $this->updateOffsets($key, $strlenRep);
                        $args = array($key, $strlenRep);
                    }
                } else {
                    // offset exists
                    // $this->updateOffsets($pos, $strlenRep - strlen($token));
                    $args = array($this->pos, $strlenRep - \strlen($token));
                }
            } elseif (\count($this->posStack) > 1) {
                // don't save token but need to update offsets
                $this->debug->log('don\'t add token, just update offsets!');
                // $this->updateOffsets($pos, $strlenRep - strlen($token));
                $args = array($this->pos, $strlenRep - \strlen($token));
            } else {
                $this->debug->log('don\'t add token');
            }
        }
        // $this->strOut .= $tokenReplacement;
        return $args;
    }

    /**
     * Update content offsets
     *
     * @param string  $key   content name
     * @param integer $diff  length of content being inserted (not total length). Will be negative if shortened
     * @param string  $where ='bottom' (replace|top|bottom|before|after)
     *
     * @return  void
     * @used_by get_content()
     * @used_by update_content()
     */
    protected function updateOffsets($key, $diff = 0, $where = 'replace')
    {
        // $this->debug->groupCollapsed(__CLASS__.'->'.__FUNCTION__, $key, $diff);
        // $this->debug->log('updateOffsets', $key);
        if (\is_string($key)) {
            list($keyPos,$keyLen) = $this->offsets[$key];
            $offset = \in_array($where, array('bottom','after'))
                ? $keyPos + $keyLen - 1
                : $keyPos;
        } else {
            $offset = $key;
        }
        if ($this->posStack && $offset < $this->posStack[0]) {
            $this->posStack[0] += $diff;
        }
        foreach ($this->offsets as $k => $a) {
            $kStart = $a[0];
            $kEnd = $a[1] == 0
                ? $a[0]
                : $a[0] + $a[1] - 1;
            // $this->debug->log($k, $kStart, $kEnd);
            if ($kStart == $offset && $where == 'before') {
                // $this->debug->log($k, 'start matches offset');
                $this->offsets[$k][0] += $diff;
            } elseif ($kEnd == $offset && $where == 'after') {
                // $this->debug->log($k, 'after && matching end');
                continue;
            } elseif ($kEnd >= $offset) {
                if ($kStart <= $offset) {
                    // $this->debug->log($k.' "wraps" what');
                    $this->offsets[$k][1] += $diff;
                } elseif ($where == 'replace' && $kEnd <= $offset) {    // $keyPos + $keyLen - 1
                    // $this->debug->log($k, '"child"... remove it');
                    unset($this->offsets[$k]);
                } else {
                    // $this->debug->log($k, 'starts after offset');
                    $this->offsets[$k][0] += $diff;
                }
            }
        }
        // $this->debug->groupEnd();
        return;
    }
}
