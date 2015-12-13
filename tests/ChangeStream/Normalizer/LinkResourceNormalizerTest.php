<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository\Tests\ChangeStream\Normalizer;

use Puli\Repository\ChangeStream\Normalizer\LinkResourceNormalizer;
use Puli\Repository\Resource\LinkResource;

/**
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
class LinkResourceNormalizerTest extends AbstractResourceNormalizerTest
{
    /**
     * @return LinkResourceNormalizer
     */
    public function createNormalizer()
    {
        return new LinkResourceNormalizer();
    }

    /**
     * @return LinkResource
     */
    public function createSupportedResource()
    {
        return new LinkResource('/target/path', '/supported');
    }

    public function testNormalizeDenormalizeLink()
    {
        $normalizer = $this->createNormalizer();

        $resource = $this->createSupportedResource();
        $normalized = $normalizer->denormalize($normalizer->normalize($resource));

        $this->assertEquals($resource->getTargetPath(), $normalized->getTargetPath());
    }
}
