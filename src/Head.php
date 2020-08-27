<?php

namespace bdk\TinyFrame;

use bdk\ArrayUtil;
use bdk\Html;
// use bdk\Pager\ExtensionInterface;

/**
 * Head
 */
class Head extends Component implements ContentInterface
{

    protected $tags = array();
    protected $addDefaultAttribs = true;

    /**
     * add meta, script, base, stylesheet, and other <link rel="xxx"> tags
     * http://developers.whatwg.org/links.html#linkTypes
     *
     * A few examples
     *  'description','This is a test'                  // <meta name="description" content="This is a test" />
     *  'meta','refresh','5; url=/'                     // <meta http-equiv="refresh" content="5; url=/" />
     *  'alternative','rssurl'                          // <link rel="alternate" type="application/rss+xml" href="rssurl" />
     *  'alternative','rssurl', array('title'=>'subscribe to my feed')
     *  'stylesheet','style.css'                        // <link rel="stylesheet" type="text/css" media="all" href="style.css" />
     *  'stylesheet','style.css',array('media'=>'screen')
     *  'script','/script.js'                           // <script type="text/javascript" src="script.js"></script>
     *
     * @param mixed $what,...             script, meta, base, or <link rel="xxx"> value (stylesheet,alternate, icon, etc)
     * @param mixed $prop_or_value,...    optional : for meta info this would be "description", "keywords", etc
     *          for scripts, stylesheets, etc, this would be the url
     * @param mixed $value_or_attribs,... optional for meta, pass the value
     *          for scripts and stylesheets, may pass array of additional attributes
     *
     * @return void
     */
    public function addTag()
    {
        $args = \func_get_args();
        $attribs = \call_user_func_array(array($this,'headTagAttribs'), $args);
        $this->tags[] = $attribs;
        $contentKey = $attribs['tagname'] == 'base'
            ? 'tagsMeta'
            : 'tags' . \ucfirst($attribs['tagname']);
        $isTokenRendered = $this->renderer->isTokenRendered($contentKey);
        // $this->debug->warn('isTokenRendered', $contentKey, $isTokenRendered);
        if ($attribs['tagname'] == 'script' && !$isTokenRendered) {
            // also check for tagsScriptTrue / tagsScriptFalse
            $defer = isset($attribs['defer']) && $attribs['defer'] ? ' true' : ' false';
            $contentKey = 'tagsScript' . $defer;
            // $already_out = isset($this->content[$contentKey]);
            $isTokenRendered = $this->renderer->isTokenRendered($contentKey);
        }
        if ($isTokenRendered) {
            // $this->debug->warn('add tag rendered!!!!!');
            $this->addTagRendered($contentKey, $attribs);
        }
        return;
    }

    /**
     * get browser title for page
     *
     * @return string
     */
    /*
    public function getTitle()
    {
        // $this->debug->groupCollapsed(__CLASS__.'->'.__FUNCTION__);
        $string = '';
        $seperator = ': ';
        $path = $this->page->properties['path'];
        $currentPage = $this->site->site;
        // $this->debug->log('currentPage', $currentPage);
        do {
            if (isset($currentPage['content']['title'])) {
                $string = $currentPage['content']['title'];
            } elseif (isset($currentPage['name'])) {
                $string .= ( !empty($string) ? $seperator : '' ).$currentPage['name'];
            }
            $level = \array_shift($path);
            // $this->debug->log('level', $level);
            $currentPage = isset($currentPage['pages'][$level])
                ? $currentPage['pages'][$level]
                : null;
        } while ($level);
        // $this->debug->log('title', $string);
        // $this->debug->groupEnd();
        return $string;
    }
    */

    public function getContentGenerators()
    {
        return array(
            'getTagsLink',
            'getTagsMeta',
            'getTagsScript',
        );
    }

    /**
     * build string of <link> tags
     * automatically adds default rel=canonical and rel=up  [via get_link() -> params will not be included]
     * later links have precidence
     *
     * @return string
     */
    public function getTagsLink()
    {
        $str = '';
        $allowMultiple = array(
            'stylesheet' => array('href'),
            'alternative' => array('language','media','type'),
            'apple-touch-icon' => array('sizes'),
            'icon' => array('media','sizes','type'),
        );
        /*
            collect the link tags
        */
        $tags = $this->findTags('link');
        $tagsGrouped = array();    // group tags by rel attrib
        foreach ($tags as $tag) {
            $rel = $tag['rel'];
            if ($rel == 'canonical' && \strpos($tag['href'], 'http') === false) {
                $tag['href'] = $this->fullyQualifyUrl($tag['href']);
            }
            if (isset($allowMultiple[$rel])) {
                // make sure href has no template tokens
                $uniqueStr = '';
                foreach ($allowMultiple[$rel] as $k) {
                    $uniqueStr .= isset($tag[$k]) ? $tag[$k] : '';
                }
                // $tag['href'] = $this->page->template->render($tag['href']);
                $tagsGrouped[$rel][$uniqueStr] = $tag;
            } else {
                $tagsGrouped[$rel] = array( $tag );
            }
        }
        $tags = array();
        foreach ($tagsGrouped as $a) {
            \array_splice($tags, \count($tags), 0, $a);
        }
        if (!isset($tagsGrouped['up'])) {
            $tags[] = $this->getTagsLinkUp();
        }
        if (!isset($tagsGrouped['canonical'])) {
            $tags[] = $this->getTagsLinkCan();
        }
        // sort the tags (don't sort by href!  order matters)
        $tags = \array_filter($tags);
        $tags = ArrayUtil::fieldSort($tags, array('rel','type'));
        foreach ($tags as $attribs) {
            $this->tags[] = $attribs;
            $tagname = $attribs['tagname'];
            unset($attribs['tagname']);
            $str .= $this->buildTag($tagname, $attribs) . "\n\t";
        }
        $str = \rtrim($str);
        return $str;
    }

    protected function buildTag($tagname, $attribs)
    {
        if ($tagname == 'script' && $attribs['type'] == 'text/javascript') {
            $attribs['type'] = null; // type attribute is unnecessary for JavaScript resources
        }
        if ($tagname === 'style' && $attribs['type'] == 'text/css') {
            $attribs['type'] = null;    // The type attribute for the style element is not needed and should be omitted
        }
        if (isset($attribs['href'])) {
            // make sure url is properly encoded
            $urlParts = Html::parseUrl($attribs['href']);
            // $this->debug->log('urlParts', $urlParts);
            if ($urlParts['params']) {
                if (!$urlParts['scheme']) {
                    $urlParts['scheme'] = 'omit';
                }
                unset($attribs['query']);
                // if (!$urlParts['scheme']) {
                    // $urlParts['scheme'] = 'omit';
                // }
                $attribs['href'] = Html::buildUrl($urlParts);
            }
        }
        return Html::buildTag($tagname, $attribs);
    }

    /**
     * builds string of <meta> & <base> tags
     * automatically adds rel=canonical and rel=up
     *
     * @return string
     *
     * @todo make og tags optional
     * @link http://developers.facebook.com/docs/opengraphprotocol/
     * @link https://dev.twitter.com/docs/cards
     */
    public function getTagsMeta()
    {
        $this->debug->groupCollapsed(__METHOD__);
        $str = '';
        $tagsMeta = array(
            'og:title'      => '{{title}}',     // og:title = pretty much same as <title>
            'og:type'       => 'website',       // http://developers.facebook.com/docs/opengraph/#types
            'og:image'      => null,            // image URL - The image must be at least 50px by 50px and have a maximum aspect ratio of 3:1. We support PNG, JPEG and GIF formats.
            'og:url'        => null,            // same as link rel="canonical"
            'og:site_name'  => null,            // array_path($GLOBALS, 'site/name'),
            'og:description' => null,            // pretty much equivalent to meta description
            // fb:admins
            // fb:app_id
        );
        $tagsBase = array();
        /*
            collect the meta tags
            there can only be one tag per name/property
            newer overwrites previous
        */
        $tags = $this->findTags(array('meta','base'));
        foreach ($tags as $tag) {
            if ($tag['tagname'] == 'meta') {
                $keys = \array_diff(\array_keys($tag), array('tagname','content'));
                $key = \reset($keys);
                $key = $key == 'http-equiv'
                    ? $key . ' ' . $tag[ $key ]
                    : $tag[ $key ];
                $tagsMeta[$key] = $tag;
            } elseif ($tag['tagname'] == 'base') {
                $keys = \array_diff(\array_keys($tag), array('tagname'));
                $key = \reset($keys);
                $tagsBase[$key] = $tag;
            }
        }
        $tagsMeta = $this->getTagsMetaClean($tagsMeta);
        \ksort($tagsMeta);
        foreach ($tagsMeta as $attribs) {
            $this->tags[] = $attribs;
            $tagname = $attribs['tagname'];
            unset($attribs['tagname']);
            $str .= $this->buildTag($tagname, $attribs) . "\n\t";
        }
        foreach ($tagsBase as $attribs) {
            $this->tags[] = $attribs;
            $tagname = $attribs['tagname'];
            unset($attribs['tagname']);
            $str .= $this->buildTag($tagname, $attribs) . "\n\t";
        }
        $str = \rtrim($str); // remove final "\n\t"
        $this->debug->groupEnd();
        return $str;
    }

    /**
     * Build <script> tags
     * if the same src is present multiple times, the FIRST occurance will be used
     * Script tags will be sorted by the data-sort attribute
     *      default value = 0
     *      data-sort attribute will not be output
     *
     * @param boolean $defered (optional) if provided, whether to return tags with or without the defer attribute
     *                      if not specified,tags both with and without the defer attrib will be output
     *                      this param effectively allows script tags to be inserted in both the <head> and <body> (defer) of the page
     *                      [::tags_script false::]
     *                      [::tags_script true::]
     *
     * @return string
     */
    public function getTagsScript($defered = null)
    {
        $str = '';
        if (\is_string($defered)) {
            $defered = $defered === 'true';
        }
        $tags = array();
        $srcs = array();
        $tags = $this->findTags('script');
        foreach ($tags as $k => $tag) {
            unset($tags[$k]);
            if (!empty($tag['src'])) {
                // check if src has already been collected.. if so, skip
                $tag['src'] = $this->renderer->render($tag['src']);
                if (\in_array($tag['src'], $srcs)) {
                    continue;
                }
                $srcs[] = $tag['src'];
            }
            $tags[$k] = $tag;
        }
        foreach ($tags as $attribs) {
            $this->tags[] = $attribs;
            if (
                $defered === null
                || $defered
                    && !empty($attribs['defer'])
                || !$defered
                    && empty($attribs['defer'])
            ) {
                // unset($tag['data-sort']);   // don't output the data-sort attrib
                if ($defered === true) {
                    unset($attribs['defer']);   // leave attrib if outputing both non-defered & defered
                }
                $tagname = $attribs['tagname'];
                unset($attribs['tagname']);
                $str .= $this->buildTag($tagname, $attribs) . "\n\t";
            }
        }
        $str = \rtrim($str);
        return $str;
    }

    public function removeTag()
    {
        $args = \func_get_args();
        $this->addDefaultAttribs = false;
        $attribs = \call_user_func_array(array($this,'headTagAttribs'), $args);
        $this->addDefaultAttribs = true;
        foreach ($this->tags as $i => $tag) {
            if (\array_intersect_assoc($attribs, $tag) == $attribs) {
                unset($this->tags[$i]);
                break;
            }
        }
    }

    /**
     * [addTagRendered description]
     *
     * @param string $contentKey token/key that we're updating
     * @param array  $attribs    tag attributes
     *
     * @return void
     */
    protected function addTagRendered($contentKey, $attribs)
    {
        $isTagAlreadyIncluded = false;
        $search = array();
        if ($attribs['tagname'] == 'link') {
            $search = array(
                'tagname' => 'link',
                'href'  => $attribs['href'],
            );
        } elseif ($attribs['tagname'] == 'script') {
            $search = array(
                'tagname' => 'script',
                'src'   => $attribs['src'],
            );
        }
        if ($search) {
            $found = ArrayUtil::searchFields($this->tags, $search);
            if (\count($found) > 1) { // > 1 because already added above
                $isTagAlreadyIncluded = true;
            }
        }
        if (!$isTagAlreadyIncluded) {
            $tagname = $attribs['tagname'];
            unset($attribs['tagname']);
            $tag = $this->buildTag($tagname, $attribs);
            $this->content->update($contentKey, $tag);
        }
    }

    /**
     * retreives (and removes) tags from this->tags
     *
     * @param array|string $tagnames cname(s) to search for
     *
     * @return array
     */
    protected function findTags($tagnames)
    {
        $tags = array();
        if (!\is_array($tagnames)) {
            $tagnames = array( $tagnames );
        }
        if (empty($this->tags)) {
            $this->tags = array();
        }
        foreach ($this->tags as $k => $tag) {
            if (!isset($tag['tagname'])) {
                $tag = \is_string($k)
                    ? $this->headTagAttribs($k, $tag)
                    : $this->headTagAttribs($tag);
                $this->tags[$k] = $tag;
            }
        }
        foreach ($tagnames as $tagname) {
            foreach ($this->tags as $i => $tag) {
                if ($tag['tagname'] == $tagname) {
                    unset($this->tags[$i]);
                    $tags[] = $tag;
                }
            }
        }
        return $tags;
    }

    /**
     * [fullyQualifyUrl description]
     *
     * @param string $url url
     *
     * @return string
     */
    protected function fullyQualifyUrl($url)
    {
        if (\strpos($url, 'http') === false) {
            $url = Html::buildUrl(array(
                'url'   => $url,
                // 'scheme' => $this->site->isSecured ? 'https' : 'http',
                'host'  => \preg_match('/^\w+\.\w+$/', $_SERVER['HTTP_HOST'])
                            ? 'www.' . $_SERVER['HTTP_HOST']
                            : $_SERVER['HTTP_HOST'],
            ));
        }
        return $url;
    }

    /**
     * returns default attribs for <link rel="up" />
     *
     * @return array
     */
    protected function getTagsLinkUp()
    {
        /*
        // $this->debug->log('creating up link');
        $path = $this->page->properties['path'];
        $pathUp = \end($path) == 'index'
            ? \array_slice($path, 0, -2)
            : \array_slice($path, 0, -1);
        return array(
            'tagname' => 'link',
            'rel'   => 'up',
            'href'  => $this->router->getUrl($pathUp),
        );
        */
    }

    /**
     * returns default attribs for <link rel="canonical" />
     *
     * @return array
     */
    protected function getTagsLinkCan()
    {
        $link =  array(
            'tagname' => 'link',
            'rel' => 'canonical',
            'href' => $this->router->getUrl($this->route->name, $this->route->attributes, array('fullUrl' => true)),
        );
        // $this->debug->warn('canonical!!!!!!!!!', $link);
        return $link;
    }

    /**
     * remove nulls, sets defaults, make sure urls are fully qualified
     *
     * @param array $tagsMeta array of meta tags
     *
     * @return array
     */
    protected function getTagsMetaClean($tagsMeta)
    {
        /*
            set og:url if not set
        */
        if (empty($tagsMeta['og:url']['content'])) {
            // og:url is equivalent to <link rel="canonical">
            $this->getTagsLink();
            $found = ArrayUtil::searchFields($this->tags, array(
                'tagname' => 'link',
                'rel' => 'canonical',
            ));
            $found = \reset($found);
            if ($found) {
                $tagsMeta['og:url'] = $found['href'];
            }
        }
        /*
            make sure they're all arrays
        */
        foreach ($tagsMeta as $k => $mixed) {
            if ($mixed === null) {
                unset($tagsMeta[$k]);
            } elseif (!\is_array($mixed)) {
                $tagsMeta[$k] = $this->headTagAttribs('meta', $k, $mixed);
            }
        }
        /*
            make sure og:image & twitter:image have fully qualified urls
        */
        foreach (array('og:image','twitter:image') as $k) {
            if (!empty($tagsMeta[$k]['content']) && \strpos($tagsMeta[$k]['content'], 'http') === false) {
                $tagsMeta[$k]['content'] = $this->fullyQualifyUrl($tagsMeta[$k]['content']);
            }
        }
        return $tagsMeta;
    }

    /**
     * parses parameters that have been passed to addTag() or stored in self::tags
     * returns array of complete attributes for the tag (including cname)
     *
     * @return array
     */
    protected function headTagAttribs()
    {
        $args = \func_get_args();
        $attribs = $this->headTagArgs($args);
        if ($this->addDefaultAttribs) {
            $what = $attribs['tagname'] == 'link'
                ? \strtolower($attribs['rel'])
                : $attribs['tagname'];
            $defaultType = array(
                'script'        => 'text/javascript',
                'stylesheet'    => 'text/css',
                'icon'          => 'infer',
                'alternate'     => 'application/rss+xml',
            );
            $attribsDefault = array(
                'type'  => ArrayUtil::pathGet($defaultType, $what),
                'media' => $what == 'stylesheet'
                    ? 'all'
                    : null,
            );
            $attribs = \array_merge($attribsDefault, $attribs);
            if ($attribs['type'] == 'infer') {
                $mimes = array(
                    'bmp' => 'image/bmp',
                    'gif' => 'image/gif',
                    'ico' => 'image/vnd.microsoft.icon',
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'tif' => 'image/tiff',
                    'tiff' => 'image/tiff',
                );
                $ext = \pathinfo($attribs['href'], PATHINFO_EXTENSION);
                $attribs['type'] = $mimes[$ext];
            }
        }
        $attribs = \array_filter($attribs, 'strlen');    // toss null
        return $attribs;
    }

    /**
     * convert args to attribs
     *
     * @param array $args function arguments
     *
     * @return array
     */
    protected function headTagArgs($args)
    {
        $attribs = array(
            'tagname' => null,
        );
        if (\count($args) == 1 && \is_array($args[0]) && isset($args[0][0])) {
            // $this->debug->log('only arg & it is an indexed array.. making it the args list');
            $args = $args[0];
        }
        $metaTags = array(
            'author',
            'creator',
            'description',
            'generator',
            'googlebot',
            'keywords',
            'referrer',
            'robots',
            'slurp',
            'viewport',
        );
        $httpEquiv = array(
            'refresh',
        );
        foreach ($args as $arg) {
            if (\is_array($arg)) {
                $attribs = \array_merge($attribs, $arg);
            } elseif (!$attribs['tagname']) {
                // determine tag name
                if (\in_array($arg, array('base','link','meta','script'))) {
                    $attribs['tagname'] = $arg;
                } elseif (\preg_match('/^(og|fb):/', $arg)) {
                    $attribs['tagname'] = 'meta';
                    $attribs['property'] = $arg;
                } elseif (\in_array($arg, $metaTags)) {
                    $attribs['tagname'] = 'meta';
                    $attribs['name'] = $arg;
                } elseif (\in_array($arg, $httpEquiv)) {
                    $attribs['tagname'] = 'meta';
                    $attribs['http-equiv'] = $arg;
                } else {
                    $attribs['tagname'] = 'link';
                    $attribs['rel'] = $arg;
                }
            } else {
                if ($attribs['tagname'] == 'meta') {
                    $nameProps = array('name','property','http-equiv');
                    if (\array_intersect_key($attribs, \array_flip($nameProps))) {
                        $key = 'content';
                    } elseif (\in_array($arg, $httpEquiv)) {
                        $key = 'http-equiv';
                    } elseif (\preg_match('/^(og|fb):/', $arg)) {
                        // og:*, fb:*
                        $key = 'property';
                    } else {
                        $key = 'name';
                    }
                } elseif ($attribs['tagname'] == 'script') {
                    $key = 'src';
                } elseif ($attribs['tagname'] == 'link') {
                    $key = !isset($attribs['rel']) && \count($args) == 3
                        ? 'rel'
                        : 'href';
                } elseif ($attribs['tagname'] == 'base') {
                    $key = $arg;
                    $arg = $args[2];
                }
                $attribs[$key] = $arg;
            }
        }
        if (!$attribs['tagname']) {
            $attribs['tagname'] = 'link';
        }
        if (isset($attribs['rel']) && \strtolower($attribs['rel']) == 'shortcut icon') {
            $attribs['rel'] = 'icon';
        }
        return $attribs;
    }
}
