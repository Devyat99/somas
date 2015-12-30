<?php

/*
 * This file is part of the puli/repository package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Repository;

use Puli\Repository\Api\ChangeStream\ChangeStream;
use Puli\Repository\Api\Resource\FilesystemResource;
use Puli\Repository\Api\Resource\PuliResource;
use Puli\Repository\Api\ResourceCollection;
use Puli\Repository\Api\ResourceNotFoundException;
use Puli\Repository\Api\UnsupportedResourceException;
use Puli\Repository\Resource\Collection\ArrayResourceCollection;
use Puli\Repository\Resource\DirectoryResource;
use Puli\Repository\Resource\FileResource;
use Puli\Repository\Resource\GenericResource;
use Puli\Repository\Resource\LinkResource;
use RuntimeException;
use Webmozart\Assert\Assert;
use Webmozart\Json\JsonDecoder;
use Webmozart\Json\JsonEncoder;
use Webmozart\PathUtil\Path;

/**
 * Abstract base for Path mapping repositories.
 *
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
abstract class AbstractJsonRepository extends AbstractEditableRepository
{
    /**
     * @var array
     */
    protected $json;

    /**
     * @var string
     */
    protected $baseDirectory;

    /**
     * @var string
     */
    private $path;

    /**
     * @var string
     */
    private $schemaPath;

    /**
     * @var JsonEncoder
     */
    private $encoder;

    /**
     * Creates a new repository.
     *
     * @param string            $path          The path to the JSON file. If
     *                                         relative, it must be relative to
     *                                         the base directory.
     * @param string            $baseDirectory The base directory of the store.
     *                                         Paths inside that directory are
     *                                         stored as relative paths. Paths
     *                                         outside that directory are stored
     *                                         as absolute paths.
     * @param ChangeStream|null $changeStream  If provided, the repository will
     *                                         append resource changes to this
     *                                         change stream.
     * @param bool              $validateJson  Whether to validate the JSON file
     *                                         against the schema. Slow but
     *                                         spots problems.
     */
    public function __construct($path, $baseDirectory, ChangeStream $changeStream = null, $validateJson = false)
    {
        parent::__construct($changeStream);

        $this->baseDirectory = $baseDirectory;
        $this->path = Path::makeAbsolute($path, $baseDirectory);
        $this->encoder = new JsonEncoder();

        if ($validateJson) {
            $this->schemaPath = realpath(__DIR__.'/../res/schema/path-mappings-schema-1.0.json');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function add($path, $resource)
    {
        if (null === $this->json) {
            $this->load();
        }

        $path = $this->sanitizePath($path);

        if ($resource instanceof ResourceCollection) {
            $this->ensureDirectoryExists($path);

            foreach ($resource as $child) {
                $this->addResource($path.'/'.$child->getName(), $child);
            }

            $this->flush();

            return;
        }

        $this->ensureDirectoryExists(Path::getDirectory($path));
        $this->addResource($path, $resource);

        $this->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function get($path)
    {
        if (null === $this->json) {
            $this->load();
        }

        $path = $this->sanitizePath($path);
        $references = $this->getReferencesForPath($path);

        // Might be null, don't use isset()
        if (array_key_exists($path, $references)) {
            return $this->createResource($path, $references[$path]);
        }

        throw ResourceNotFoundException::forPath($path);
    }

    /**
     * {@inheritdoc}
     */
    public function find($query, $language = 'glob')
    {
        if (null === $this->json) {
            $this->load();
        }

        $this->validateSearchLanguage($language);
        $query = $this->sanitizePath($query);
        $results = $this->createResources($this->getReferencesForGlob($query));

        ksort($results);

        return new ArrayResourceCollection(array_values($results));
    }

    /**
     * {@inheritdoc}
     */
    public function contains($query, $language = 'glob')
    {
        if (null === $this->json) {
            $this->load();
        }

        $this->validateSearchLanguage($language);
        $query = $this->sanitizePath($query);

        // Stop on the first result
        $results = $this->getReferencesForGlob($query, true);

        return !empty($results);
    }

    /**
     * {@inheritdoc}
     */
    public function remove($query, $language = 'glob')
    {
        if (null === $this->json) {
            $this->load();
        }

        $this->validateSearchLanguage($language);
        $query = $this->sanitizePath($query);

        Assert::notEmpty(trim($query, '/'), 'The root directory cannot be removed.');

        $removed = $this->removeReferences($query);

        $this->flush();

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        if (null === $this->json) {
            $this->load();
        }

        // Subtract root which is not deleted
        $removed = count($this->getReferencesForRegex('/', '~.~')) - 1;

        $this->json = array();

        $this->flush();

        return $removed;
    }

    /**
     * {@inheritdoc}
     */
    public function listChildren($path)
    {
        if (null === $this->json) {
            $this->load();
        }

        $path = $this->sanitizePath($path);
        $results = $this->createResources($this->getReferencesInDirectory($path));

        if (empty($results)) {
            $pathResults = $this->getReferencesForPath($path);

            if (empty($pathResults)) {
                throw ResourceNotFoundException::forPath($path);
            }
        }

        ksort($results);

        return new ArrayResourceCollection(array_values($results));
    }

    /**
     * {@inheritdoc}
     */
    public function hasChildren($path)
    {
        if (null === $this->json) {
            $this->load();
        }

        $path = $this->sanitizePath($path);

        // Stop on the first result
        $results = $this->getReferencesInDirectory($path, true);

        if (empty($results)) {
            $pathResults = $this->getReferencesForPath($path);

            if (empty($pathResults)) {
                throw ResourceNotFoundException::forPath($path);
            }

            return false;
        }

        return true;
    }

    /**
     * Inserts a path reference into the JSON file.
     *
     * The path reference can be:
     *
     *  * a link starting with `@`
     *  * an absolute filesystem path
     *
     * @param string      $path      The Puli path.
     * @param string|null $reference The path reference.
     */
    abstract protected function insertReference($path, $reference);

    /**
     * Removes all path references matching the given glob from the JSON file.
     *
     * @param string $glob The glob for a list of Puli paths.
     */
    abstract protected function removeReferences($glob);

    /**
     * Returns the references for a given Puli path.
     *
     * Each reference returned by this method can be:
     *
     *  * `null`
     *  * a link starting with `@`
     *  * an absolute filesystem path
     *
     * The result has either one entry or none, if no path was found. The key
     * of the single entry is the path passed to this method.
     *
     * @param string $path The Puli path.
     *
     * @return string[]|null[] A one-level array of references with Puli paths
     *                         as keys. The array has at most one entry.
     */
    abstract protected function getReferencesForPath($path);

    /**
     * Returns the references matching a given Puli path glob.
     *
     * Each reference returned by this method can be:
     *
     *  * `null`
     *  * a link starting with `@`
     *  * an absolute filesystem path
     *
     * The keys of the returned array are Puli paths. Their order is undefined.
     *
     * @param string $glob        The glob.
     * @param bool   $stopOnFirst Whether to stop after finding a first result.
     *
     * @return string[]|null[] A one-level array of references with Puli paths
     *                         as keys.
     */
    abstract protected function getReferencesForGlob($glob, $stopOnFirst = false);

    /**
     * Returns the references matching a given Puli path regular expression.
     *
     * Each reference returned by this method can be:
     *
     *  * `null`
     *  * a link starting with `@`
     *  * an absolute filesystem path
     *
     * The keys of the returned array are Puli paths. Their order is undefined.
     *
     * @param string $staticPrefix The static prefix of all Puli paths matching
     *                             the regular expression.
     * @param string $regex        The regular expression.
     * @param bool   $stopOnFirst  Whether to stop after finding a first result.
     *
     * @return string[]|null[] A one-level array of references with Puli paths
     *                         as keys.
     */
    abstract protected function getReferencesForRegex($staticPrefix, $regex, $stopOnFirst = false);

    /**
     * Returns the references in a given Puli path.
     *
     * Each reference returned by this method can be:
     *
     *  * `null`
     *  * a link starting with `@`
     *  * an absolute filesystem path
     *
     * The keys of the returned array are Puli paths. Their order is undefined.
     *
     * @param string $path        The Puli path.
     * @param bool   $stopOnFirst Whether to stop after finding a first result.
     *
     * @return string[]|null[] A one-level array of references with Puli paths
     *                         as keys.
     */
    abstract protected function getReferencesInDirectory($path, $stopOnFirst = false);

    /**
     * Adds a filesystem resource to the JSON file.
     *
     * @param string             $path     The Puli path.
     * @param FilesystemResource $resource The resource to add.
     */
    protected function addFilesystemResource($path, FilesystemResource $resource)
    {
        $resource->attachTo($this, $path);

        $this->insertReference($path, $resource->getFilesystemPath());
    }

    /**
     * Returns whether a reference contains a link.
     *
     * @param string $reference The reference.
     *
     * @return bool Whether the reference contains a link.
     */
    protected function isLinkReference($reference)
    {
        return isset($reference{0}) && '@' === $reference{0};
    }

    /**
     * Returns whether a reference contains an absolute or relative filesystem
     * path.
     *
     * @param string $reference The reference.
     *
     * @return bool Whether the reference contains a filesystem path.
     */
    protected function isFilesystemReference($reference)
    {
        return null !== $reference && !$this->isLinkReference($reference);
    }

    /**
     * Loads the JSON file.
     */
    private function load()
    {
        $decoder = new JsonDecoder();

        $this->json = file_exists($this->path)
            ? (array) $decoder->decodeFile($this->path, $this->schemaPath)
            : array();

        // The root node always exists
        if (!isset($this->json['/'])) {
            $this->json['/'] = null;
        }

        // Make sure the JSON is sorted in reverse order
        krsort($this->json);
    }

    /**
     * Writes the JSON file.
     */
    private function flush()
    {
        // The root node always exists
        if (!isset($this->json['/'])) {
            $this->json['/'] = null;
        }

        // Always save in reverse order
        krsort($this->json);

        $this->encoder->encodeFile((object) $this->json, $this->path, $this->schemaPath);
    }

    /**
     * Adds all ancestor directories of a path to the repository.
     *
     * @param string $path A Puli path.
     */
    private function ensureDirectoryExists($path)
    {
        if (array_key_exists($path, $this->json)) {
            return;
        }

        // Recursively initialize parent directories
        if ('/' !== $path) {
            $this->ensureDirectoryExists(Path::getDirectory($path));
        }

        $this->json[$path] = null;
    }

    /**
     * Turns a reference into a resource.
     *
     * @param string      $path      The Puli path.
     * @param string|null $reference The reference.
     *
     * @return PuliResource The resource.
     */
    private function createResource($path, $reference)
    {
        if (null === $reference) {
            $resource = new GenericResource();
        } elseif (isset($reference{0}) && '@' === $reference{0}) {
            $resource = new LinkResource(substr($reference, 1));
        } elseif (is_dir($reference)) {
            $resource = new DirectoryResource($reference);
        } elseif (is_file($reference)) {
            $resource = new FileResource($reference);
        } else {
            throw new RuntimeException(sprintf(
                'Trying to create a FilesystemResource on a non-existing file or directory "%s"',
                $reference
            ));
        }

        $resource->attachTo($this, $path);

        return $resource;
    }

    /**
     * Turns a list of references into a list of resources.
     *
     * The references are expected to be in the format returned by
     * {@link getReferencesForPath()}, {@link getReferencesForGlob()} and
     * {@link getReferencesInDirectory()}.
     *
     * The result contains Puli paths as keys and {@link PuliResource}
     * implementations as values. The order of the results is undefined.
     *
     * @param string[]|null[] $references The references indexed by Puli paths.
     *
     * @return array
     */
    private function createResources(array $references)
    {
        foreach ($references as $path => $reference) {
            $references[$path] = $this->createResource($path, $reference);
        }

        return $references;
    }

    /**
     * Adds a resource to the repository.
     *
     * @param string                          $path     The Puli path to add the
     *                                                  resource at.
     * @param FilesystemResource|LinkResource $resource The resource to add.
     */
    private function addResource($path, $resource)
    {
        if (!$resource instanceof FilesystemResource && !$resource instanceof LinkResource) {
            throw new UnsupportedResourceException(sprintf(
                'The %s only supports adding FilesystemResource and '.
                'LinkedResource instances. Got: %s',
                // Get the short class name
                $this->getShortClassName(get_class($this)),
                $this->getShortClassName(get_class($resource))
            ));
        }

        // Don't modify resources attached to other repositories
        if ($resource->isAttached()) {
            $resource = clone $resource;
        }

        if ($resource instanceof LinkResource) {
            $resource->attachTo($this, $path);

            $this->insertReference($path, '@'.$resource->getTargetPath());
        } else {
            // Extension point for the optimized repository
            $this->addFilesystemResource($path, $resource);
        }

        $this->appendToChangeStream($resource);
    }

    /**
     * Returns the short name of a fully-qualified class name.
     *
     * @param string $className The fully-qualified class name.
     *
     * @return string The short class name.
     */
    private function getShortClassName($className)
    {
        if (false !== ($pos = strrpos($className, '\\'))) {
            return substr($className, $pos + 1);
        }

        return $className;
    }
}
