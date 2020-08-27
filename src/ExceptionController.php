<?php

namespace bdk\TinyFrame;

// use Pimple\Container;
use bdk\TinyFrame\Component;
use bdk\TinyFrame\Controller;
use bdk\TinyFrame\Exception\ExitException;
use bdk\TinyFrame\Exception\HttpException;
use bdk\TinyFrame\Exception\RedirectException;

/**
 * Base controller class
 */
class ExceptionController extends Controller
{

    /**
     * Get content filepath for current route
     *
     * @return string
     */
    public function getFilepath()
    {
        $this->debug->info(__METHOD__);
        $directories = array(
            $this->config['dirContent'] . '/error',
            __DIR__ . '/content',
        );
        $code = $this->exception->getCode();
        foreach ($directories as $dir) {
            if (!$dir) {
                continue;
            }
            $filepaths = array(
                $dir . DIRECTORY_SEPARATOR . 'error' . $code . '.php',
                $dir . DIRECTORY_SEPARATOR . 'error' . $code . '.html',
                $dir . DIRECTORY_SEPARATOR . 'error.php',
                $dir . DIRECTORY_SEPARATOR . 'error.html',
            );
            foreach ($filepaths as $filepath) {
                $this->debug->log('filepath', $filepath);
                if (\is_file($filepath)) {
                    return $filepath;
                }
            }
        }
    }

    /**
     * Output reponse to an exception
     *
     * @param \Exception $e Exception
     *
     * @return Response
     */
    public function handleException(\Exception $e)
    {
        $this->debug->warn('ob level', ob_get_level());
        $levels = \ob_get_level();
        for ($i = 0; $i < $levels; $i++) {
            ob_end_clean();
        }
        $this->exception = $e;
        // return $this->response->withBody($this->streamify($this->getFilepath()));
        if ($e instanceof ExitException) {
            $this->debug->info('ExitException');
            $this->template = null;
            return $this->response;
        } elseif ($e instanceof RedirectException) {
            $this->debug->info('RedirectException');
            $this->template = null;
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
                        ->withHeader('Content-Type', 'text/html')
                        ->withBody($this->streamify($this->getFilepath())),
                )
            );
            return $event['response'];
        } else {
            // $this->debug->warn('uncaught exception', $e);
            $this->template = 'error';
            $event = $this->eventManager->publish(
                'tinyFrame.exception',
                $this,
                array(
                    'exception' => $e,
                    'response' => $this->response
                        ->withStatus('500')
                        ->withHeader('Content-Type', 'text/html')
                        ->withBody($this->streamify($this->getFilepath())),
                )
            );
            return $event['response'];
        }
    }
}
