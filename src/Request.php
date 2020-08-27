<?php

namespace bdk\TinyFrame;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\LazyOpenStream;

/**
 * PSR7 implementation
 */
class Request extends ServerRequest
{

    /**
     * Return a ServerRequest populated with superglobals:
     * $_GET
     * $_POST
     * $_COOKIE
     * $_FILES
     * $_SERVER
     *
     * @return ServerRequestInterface
     *
     * @see https://github.com/guzzle/psr7/issues/212
     */
    public static function fromGlobals()
    {
        $serverRequest = new static(
            isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET', // method
            self::getUriFromGlobals(),  // uri
            \getallheaders(),
            new LazyOpenStream('php://input', 'r+'), // request body
            isset($_SERVER['SERVER_PROTOCOL'])
                ? \str_replace('HTTP/', '', $_SERVER['SERVER_PROTOCOL'])
                : '1.1',
            $_SERVER
        );

        $contentType = $serverRequest->getHeader('Content-Type');
        $contentType = \array_shift($contentType);
        $contentType = \preg_replace('/^(.*?);.*/', '$1', $contentType);

        $parsedBody = null;
        if ($contentType === 'application/json') {
            $input = \file_get_contents('php://input');
            $parsedBody = \json_decode($input, true);
        }

        return $serverRequest
            ->withCookieParams($_COOKIE)
            ->withParsedBody($parsedBody ?: $_POST)
            ->withQueryParams($_GET)
            ->withUploadedFiles(self::normalizeFiles($_FILES));
    }

    /**
     * Is this a secured request?
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @return boolean
     */
    public function isSecure()
    {
        return $this->getServerParam('HTTPS') == 'on';
    }

	/**
     * Is this an XHR request?
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @return boolean
     */
    public function isXhr()
    {
        return $this->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Fetch cookie value from cookies sent by the client to the server.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The attribute name.
     * @param mixed  $default Default value to return if the attribute does not exist.
     *
     * @return mixed
     */
    public function getCookieParam($key, $default = null)
    {
        $cookies = $this->getCookieParams();
        $result = $default;
        if (isset($cookies[$key])) {
            $result = $cookies[$key];
        }
        return $result;
    }

    /**
     * Fetch request parameter value from body or query string (in that order).
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The parameter key.
     * @param string $default The default value.
     *
     * @return mixed The parameter value.
     */
    public function getParam($key, $default = null)
    {
        $postParams = $this->getParsedBody();
        $getParams = $this->getQueryParams();
        $result = $default;
        if (\is_array($postParams) && isset($postParams[$key])) {
            $result = $postParams[$key];
        } elseif (\is_object($postParams) && \property_exists($postParams, $key)) {
            $result = $postParams->$key;
        } elseif (isset($getParams[$key])) {
            $result = $getParams[$key];
        }
        return $result;
    }

    /**
     * Fetch parameter value from request body.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The param key.
     * @param mixed  $default The default value
     *
     * @return mixed
     */
    public function getParsedBodyParam($key, $default = null)
    {
        $result = $default;
        if (\is_array($this->parsedBody) && isset($this->parsedBody[$key])) {
            $result = $this->parsedBody[$key];
        } elseif (\is_object($this->parsedBody) && \property_exists($this->parsedBody, $key)) {
            $result = $this->parsedBody->$key;
        }
        return $result;
    }

    /**
     * Fetch parameter value from query string.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The param key
     * @param mixed  $default The default value
     *
     * @return mixed
     */
    public function getQueryParam($key, $default = null)
    {
        $getParams = $this->getQueryParams();
        $result = $default;
        if (isset($getParams[$key])) {
            $result = $getParams[$key];
        }
        return $result;
    }

    /**
     * Retrieve a server parameter.
     *
     * Note: This method is not part of the PSR-7 standard.
     *
     * @param string $key     The Param key
     * @param mixed  $default The default value
     *
     * @return mixed
     */
    public function getServerParam($key, $default = null)
    {
        $serverParams = $this->getServerParams();
        return isset($serverParams[$key])
        	? $serverParams[$key]
        	: $default;
    }
}
