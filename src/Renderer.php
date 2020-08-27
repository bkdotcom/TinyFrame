<?php

namespace bdk\TinyFrame;

use bdk\Str;
use bdk\TinyFrame\Component;

/**
 * Template engine
 */
class Renderer extends Component
{

    public $regex = '@\\\\?(?:
        \{\{\s*(.*?)\s*\}\}|           # {{a}}
        \[::\s*(.*?)\s*::\]|           # [::a::]
        <::\s*(.*?)\s*::>|             # <::a::>
        <!--::\s*+(.*?)\s*+::-->|      # <!--::a::-->
        \/\*::\s*(.*?)\s*::\*\/        # /*::a::*/
        )@sx';
    public $regexGroups = array(1,2,3,4,5);
    protected $updateOffsets = false;
    protected $offsets = array();
    // protected $foundToken = false;
    protected $foundEscaped = false;
    protected $posStack = array(); // current position in template
    protected $pos;
    protected $strStack = array();
    protected $strOut = '';

    /**
     * get content
     *
     * @param string $key  key of content to fetch
     * @param array  $args [<description>]
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
        /*
        $keys = \array_unique(array(
            $this->keyCamel($key),
            $key,
        ));
        if ($key{0} == '$' && \count($keys) > 1) {
            // don't mess with vars
            \array_shift($keys);
        }
        */
        if (\count($argsPassed) > 1) {
            $args = \array_slice($argsPassed, 1);
        }
        /*
        $noRender = isset($args[0]) && $args[0] === 'noRender';
        if ($noRender) {
            \array_shift($args);
        }
        */
        // $this->debug->log('args', $args);
        /*
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
            } elseif (\method_exists($this->controller, $getter)) {
                $this->debug->log('controller method exists');
                $str = \call_user_func_array(array($this->controller, $getter), $args);
            } else {
                $props = \get_object_vars($this->controller);
                // $this->debug->log('props', array_keys($props));
                foreach ($props as $prop) {
                    if (\is_object($prop) && \method_exists($prop, $getter)) {
                        $str = $prop->{$getter}();
                        break;
                    }
                }
            }
            if ($str !== false) {
                break;
            }
        }
        */
        $str = $this->content->get($key, $args);
        // @todo...  check a property... or perhaps template->istokenRendered
        /*
        if (Php::getCallerInfo(false) !== 'bdk\Pager\Template::tokenReplace'
            && !($key === 'body' && $noRender)
        ) {
            // $this->debug->info('rendering', $key, $args);
            $str = $this->page->template->render($str);
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
        $this->debug->groupEnd();
        return $str;
    }

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
     * replace tokens in template
     *
     * @param string $template template to process
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
            $this->strOut = &$this->strStack[\count($this->strStack) - 1];
        } else {
            $this->strOut = &$this->strStack[0];
        }
        $updateOffsetsWas = $this->updateOffsets;
        $this->updateOffsets = \count($this->strStack) == 1;
        do {
            $found = $this->tokensReplace();
        } while ($found);
        $this->updateOffsets = $updateOffsetsWas;
        $strOut = $this->strOut;
        if ($template !== null) {
            // $this->debug->warn('popping stack');
            \array_pop($this->strStack);
            if ($this->strStack) {
                $this->strOut = &$this->strStack[\count($this->strStack) - 1];
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
        // $this->debug->groupCollapsed(__METHOD__, $key);
        $return = '';
        if (isset($this->offsets[$key])) {
            list($keyPos, $len) = $this->offsets[$key];
            $return = \substr($this->strOut, $keyPos, $len);
            // if (!$this->strStack) {
                // $this->debug->log('this', $this);
            // }
            // $this->debug->log('renderedTokenGet', $return);
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
        // $this->debug->group(__METHOD__, $key);
        list($pos, $lenOrig) = $this->offsets[$key];
        /*
        $this->debug->warn('renderedTokenUpdate', array(
            'key' => $key,
            'pos' => $pos,
            'where' => $where,
            'strlen output' => \strlen($this->strStack[0]),
        ));
        */
        $lenDelta = \strlen($str) - $lenOrig;
        $this->updateOffsets($key, $lenDelta, $where);
        if (\in_array($where, array('replace','top','bottom'))) {
            $pre    = \substr($this->strOut, 0, $pos);  // $this->strStack[0]
            $post   = \substr($this->strOut, $pos + $lenOrig);  // $this->strStack[0]
            $this->strOut = $pre . $str . $post;
        } else {
            $pre    = \substr($this->strOut, 0, $pos);  // $this->strStack[0]
            $post   = \substr($this->strOut, $pos + $lenOrig);  // $this->strStack[0]
            $this->strOut = $pre . $str . $post;
        }
        // $this->debug->log('strOut after '.$key, str_replace(array("\n","\r",' '), array('^','^','*'), $this->strOut));
        // $this->debug->groupEnd();
    }

    /**
     * [getUpdateOffsetParams description]
     *
     * @param string  $token      [description]
     * @param string  $tokenInner [description]
     * @param integer $strlenRep  string length of replacement
     *
     * @return array [pos, strlenDelta]
     */
    protected function getUpdateOffsetParams($token, $tokenInner, $strlenRep)
    {
        // $this->debug->group(__METHOD__, $token, $tokenInner, $strlenRep);
        $args = array();
        if (!$this->updateOffsets) {
            // $this->debug->groupEnd();
            return $args;
        }
        // $pos = strlen($this->strOut);
        $storeOffsets = \preg_match('/^(\w+|tags_?script (true|false))$/i', $tokenInner);
        // $this->debug->log('storeOffsets', $storeOffsets, $matches);
        if ($storeOffsets) {
            $key = $this->keyCamel($tokenInner);
            // $this->debug->log('store key', $key);
            if (!isset($this->offsets[$key])) {
                // no existing offset
                /*
                $this->offsets[$key] = array($this->pos, 0);
                if (\count($this->posStack) > 1) {
                    // inserting content
                    // $this->updateOffsets($key, $strlenRep - strlen($token));
                    $args = array($key, $strlenRep - \strlen($token));
                } else {
                    // $this->updateOffsets($key, $strlenRep);
                    $args = array($key, $strlenRep);
                }
                */
                $this->offsets[$key] = array($this->pos, \strlen($token));
                $args = array($key, $strlenRep - \strlen($token));
            } else {
                // offset exists
                // $this->updateOffsets($pos, $strlenRep - strlen($token));
                $args = array($this->pos, $strlenRep - \strlen($token));
            }
        } else {
            // don't save token but need to update offsets
            // $this->debug->log('don\'t add token, just update offsets!');
            // $this->updateOffsets($pos, $strlenRep - strlen($token));
            $args = array($this->pos, $strlenRep - \strlen($token));
        }
        // $this->debug->groupEnd();
        return $args;
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
            . '(\S+?)'   // key
            . '(?:'
                . '\((.+)\)'
                . '|\s+((?:[\'"\$]|array|true|false|null).*)'
            . '$)/s';
        // $this->debug->log('keyParse', $key);
        if (\preg_match($regex, $key, $matches)) {
            // $this->debug->info('func()', $matches);
            $key = $matches[1];
            $args = !empty($matches[2])
                ? $matches[2]
                : $matches[3];
            $parsed = @eval('$args = array(' . $args . ');');   // eval will return null or false
                /*
            if ($parsed === false) {
                $this->resetStatus = true;
            }
            */
        } else {
            // $this->debug->info('unquoted string arg');
            $args = \explode(' ', $key, 2);
            $key = \array_shift($args);
        }
        // $this->debug->log('key', $key);
        // $this->debug->log('args', $args);
    }

    /**
     * [tokensReplace description]
     *
     * @return boolean were tokens found?
     */
    protected function tokensReplace()
    {
        $this->debug->groupCollapsed(__METHOD__);
        $strPos = 0;    // position in strIn
        $foundToken = false;
        $this->foundEscaped = false;
        $this->posStack[] = 0;
        $this->pos = &$this->posStack[\count($this->posStack) - 1];
        while (\preg_match($this->regex, $this->strOut, $matches, PREG_OFFSET_CAPTURE, $strPos)) {
            // $this->debug->log('matches', $matches);
            $token = $matches[0][0];
            $this->pos = $matches[0][1];
            // $this->debug->info('token', $token, $matches[0][1]);
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
                $foundToken = true;
                $replacement = $this->get($tokenInner); // , $os+$strOutLen, \strlen($a[0])
                $updateOffsetParams = $this->getUpdateOffsetParams($token, $tokenInner, \strlen($replacement));
                if ($updateOffsetParams) {
                    $this->updateOffsets($updateOffsetParams[0], $updateOffsetParams[1]);
                }
                $this->strOut = \substr_replace($this->strOut, $replacement, $this->pos, \strlen($token));
                $strPos = $this->pos + \strlen($replacement);
            }
            /*
            if (isset($this->offsets['script'])) {
                $this->debug->log('script', substr($this->strOut, $this->offsets['script'][0]-46, 64));
            }
            */
        }
        \array_pop($this->posStack);
        if ($this->posStack) {
            $this->pos = &$this->posStack[\count($this->posStack) - 1];
        }
        $this->debug->groupEnd();
        return $foundToken;
    }

    /**
     * Update content offsets
     *
     * @param string  $key   content name
     * @param integer $diff  length of content being inserted (not total length). Will be negative if shortened
     * @param string  $where ='bottom' (replace|top|bottom|before|after)
     *
     * @return void
     */
    protected function updateOffsets($key, $diff = 0, $where = 'replace')
    {
        // $this->debug->groupCollapsed(__METHOD__);
        if (\is_string($key)) {
            list($keyPos,$keyLen) = $this->offsets[$key];
            $offset = \in_array($where, array('bottom','after'))
                ? $keyPos + $keyLen - 1
                : $keyPos;
        } else {
            $offset = $key;
        }
        /*
        $this->debug->log(__FUNCTION__, array(
            'key' => $key,
            'diff' => $diff,
            'where' => $where,
            'offset' => $offset,
        ));
        */
        if ($this->posStack && $offset < $this->posStack[0]) {
            $this->posStack[0] += $diff;
        }
        foreach ($this->offsets as $k => $a) {
            $kStart = $a[0];
            $kEnd = $a[1] == 0
                ? $a[0]
                : $a[0] + $a[1] - 1;
            /*
            if ($k == 'script') {
                $this->debug->log($k, $kStart, $kEnd);
            }
            */
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
            /*
            if ($k == 'script') {
                $this->debug->log(
                    $k.' after',
                    $this->offsets[$k][0],
                    $this->offsets[$k][1] == 0
                        ? $this->offsets[$k][0]
                        : $this->offsets[$k][0] + $this->offsets[$k][1] - 1
                );
            }
            */
        }
        // $this->debug->groupEnd();
        return;
    }
}
