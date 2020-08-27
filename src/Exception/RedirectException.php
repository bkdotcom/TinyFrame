<?php

namespace bdk\TinyFrame\Exception;

/**
 * Throw and Exit exception rather than calling exit()
 */
class RedirectException extends \Exception
{

    const PERMANENT = 301;
    const FOUND = 302;
    const SEE_OTHER = 303;
    const PROXY = 305;
    const TEMPORARY = 307;

    private static $messages = array(
        301 => 'Moved Permanently',
            /*
                URI of the requested resource has been changed.
                RFC2616: Unless the request method was HEAD,
                    the entity of the response SHOULD contain a short hypertext note
                    with a hyperlink to the new URI.
            */
        302 => 'Found',
            /*
                URI of requested resource has been changed temporarily.
                New changes in the URI might be made in the future.
                Therefore, this same URI should be used by the client in future requests.
            */
        303 => 'See Other',
            /*
                Direct the client to get the requested resource at another URI with a GET request.
            */
        304 => 'Not Modified',
            /*
                This is used for caching purposes.
                It tells the client that the response has not been modified,
                so the client can continue to use the same cached version of the response.
            */
        305 => 'Use Proxy',     // deprecated
        307 => 'Temporary Redirect',
            /*
                Direct the client to get the requested resource
                at another URI with same method that was used in the prior request.
                This has the same semantics as the 302 Found HTTP response code,
                with the exception that the user agent must not change the HTTP method used:
                If a POST was used in the first request, a POST must be used in the second request.
            */
        308 => 'Permanent Redirect',
            /*
                This means that the resource is now permanently located at another URI, specified by the Location: HTTP Response header.
                This has the same semantics as the 301 Moved Permanently HTTP response code,
                with the exception that the user agent must not change the HTTP method used:
                If a POST was used in the first request, a POST must be used in the second request.
            */
    );

    protected $url;

    /**
     * Constructor
     *
     * @param string  $url     redirect url
     * @param integer $code    (302) response code
     * @param string  $message ("Found") http response message
     */
    public function __construct($url, $code = 302, $message = null)
    {
        parent::__construct(
            $message ? (string) $message : static::$messages[$code],
            (int) $code
        );
        if (\strpos($url, '/') === 0) {
            $url = static::getBaseUrl() . $url;
        }
        $this->url = (string) $url;
    }

    /**
     * Get redirect url
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Output header and redirect
     *
     * @return void
     */
    public function redirect()
    {
        \header('Location: ' . $this->url, true, $this->getCode());
        if ($this->getCode() == 301) {
            echo '<h1>'.$this->message.'</h1>';
            echo '<p>This page has moved to <a href="'.\htmlspecialchars($this->url).'">'.\htmlspecialchars($this->url).'</a></p>';
        }
    }

    protected static function getBaseUrl()
    {
        $parts = array(
            'scheme' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']=='on'
                        ? 'https'
                        : 'http',
            'host' => $_SERVER['HTTP_HOST'],
        );
        return $parts['scheme'].'://'.$parts['host'];
    }
}
