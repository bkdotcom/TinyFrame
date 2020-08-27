<?php

namespace bdk\TinyFrame\Exception;

/**
 * Throw Exit exception rather than calling exit()
 *
 * Class can't be called "Exit" - reserved word
 */
class ExitException extends \Exception
{
}
