<?php
/**
 * This file is part of bdk\PubSub
 *
 * @package   bdk\TinyFrame
 * @author    Brad Kent <bkfake-github@yahoo.com>
 * @license   http://opensource.org/licenses/MIT MIT
 * @copyright 2014-2018 Brad Kent
 * @version   v2.3
 * @link      http://www.github.com/bkdotcom/TinyFrame
 */

namespace bdk\TinyFrame;

interface ContentInterface
{

    /**
     * Return a list of content generators
     *
     * @return array
     */
    public function getContentGenerators();
}
