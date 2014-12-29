<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository\Tests\Resource;

use Puli\Repository\Api\Resource\BodyResource;
use Puli\Repository\Api\ResourceNotFoundException;
use Puli\Repository\Resource\AbstractResource;
use Puli\Repository\Resource\Collection\ArrayResourceCollection;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class TestFile extends AbstractResource implements BodyResource
{
    const BODY = "LINE 1\nLINE 2\n";

    private $body;

    public function __construct($path = null, $body = self::BODY)
    {
        parent::__construct($path);

        $this->body = $body;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function getSize()
    {
        return strlen($this->body);
    }

    public function getChild($relPath)
    {
        throw ResourceNotFoundException::forPath($this->getPath().'/'.$relPath);
    }

    public function hasChild($relPath)
    {
        return false;
    }

    public function hasChildren()
    {
        return false;
    }

    public function listChildren()
    {
        return new ArrayResourceCollection();
    }
}
