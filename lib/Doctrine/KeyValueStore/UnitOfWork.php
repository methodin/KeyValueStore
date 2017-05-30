<?php

/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\KeyValueStore;

use Doctrine\KeyValueStore\Id\IdHandlingStrategy;
use Doctrine\KeyValueStore\Mapping\ClassMetadataFactory;
use Doctrine\KeyValueStore\Storage\Storage;

/**
 * UnitOfWork to handle all KeyValueStore entities based on the configured
 * storage mechanism.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class UnitOfWork
{
    /**
     * @var ClassMetadataFactory
     */
    private $cmf;
    /**
     * @var Storage
     */
    private $storageDriver;
    /**
     * @var IdHandlingStrategy
     */
    private $idHandler;

    /**
     * Serialized versions of the identifiers.
     *
     * This is the after {@see IdConverterStrategy#serialize} is called on the
     * entity data.
     *
     * @var array
     */
    private $identifiers;

    private $originalData;
    private $scheduledInsertions = [];
    private $scheduledDeletions  = [];
    private $identityMap         = [];
    private $idConverter;

    public function __construct(ClassMetadataFactory $cmf, Storage $storageDriver, Configuration $config = null)
    {
        $this->cmf           = $cmf;
        $this->storageDriver = $storageDriver;
        $this->idConverter   = $config->getIdConverterStrategy();
        $this->idHandler     = $storageDriver->supportsCompositePrimaryKeys() ?
                                new Id\CompositeIdHandler() :
                                new Id\SingleIdHandler();
    }

    public function getClassMetadata($className)
    {
        return $this->cmf->getMetadataFor($className);
    }

    private function tryGetById($className, $id)
    {
        $idHash = $this->idHandler->hash($id);
        if (isset($this->identityMap[$className][$idHash])) {
            return $this->identityMap[$className][$idHash];
        }
        return;
    }

    public function reconstititute($className, $key)
    {
        $class = $this->cmf->getMetadataFor($className);
        $id    = $this->idHandler->normalizeId($class, $key);
        $data  = $this->storageDriver->find($class->storageName, $id);

        if (! $data) {
            throw new NotFoundException();
        }

        return $this->createEntity($class, $id, $data);
    }

    public function createEntity($class, $id, $data)
    {
        $object = $this->tryGetById($class->name, $id);
        if ($object) {
            return $object;
        }

        $object = $class->newInstance();

        $oid                      = spl_object_hash($object);
        $this->originalData[$oid] = $data;
        $data                     = $this->idConverter->unserialize($class, $data);

        foreach ($data as $property => $value) {
            if (isset($class->reflFields[$property])) {
                if ($class->hasAssociation($property)) {
                    $associationClass = $class->getAssociationTargetClass($property);
                    $associationMeta = $this->cmf->getMetadataFor($associationClass);
                    $class->reflFields[$property]->setValue(
                        $object,
                        $this->createEmbeddedEntity($associationMeta, $value)
                    );
                }
                $class->reflFields[$property]->setValue($object, $value);
            } else {
                $object->$property = $value;
            }
        }

        if (is_array($id)) {
            $id = current($id);
        }
        $idHash                                   = $this->idHandler->hash($id);
        $this->identityMap[$class->name][$idHash] = $object;
        $this->identifiers[$oid]                  = $id;

        return $object;
    }

    public function createEmbeddedEntity($class, $data)
    {
        $object = $class->newInstance();

        $oid                      = spl_object_hash($object);
        $this->originalData[$oid] = $data;
        $data                     = $this->idConverter->unserialize($class, $data);

        foreach ($data as $property => $value) {
            if (isset($class->reflFields[$property])) {
                $class->reflFields[$property]->setValue($object, $value);
            } else {
                $object->$property = $value;
            }
        }

        return $object;
    }


    private function computeChangeSet($class, $object)
    {
        $snapshot     = $this->getObjectSnapshot($class, $object);
        $changeSet    = [];
        $originalData = $this->originalData[spl_object_hash($object)];

        foreach ($snapshot as $field => $value) {
            if (! isset($originalData[$field]) || $originalData[$field] !== $value) {
                $changeSet[$field] = $value;
            }
        }

        if ($changeSet && ! $this->storageDriver->supportsPartialUpdates()) {
            $changeSet = array_merge($originalData, $changeSet);
        }
        return $changeSet;
    }

    private function getObjectSnapshot($class, $object)
    {
        $data = [];

        if (!$object) {
            return $data;
        }

        foreach ($class->reflFields as $fieldName => $reflProperty) {
            if (! isset($class->fields[$fieldName]['id'])) {
                if ($class->hasAssociation($fieldName)) {
                    $embeddedMeta = $this->cmf->getMetadataFor($class->getAssociationTargetClass($fieldName));
                    $data[$fieldName] = $this->getObjectSnapshot($embeddedMeta, $reflProperty->getValue($object));
                    continue;
                }

                if (is_object($object)) {
                    $data[$fieldName] = $reflProperty->getValue($object);
                }
            }
        }

        foreach (get_object_vars($object) as $property => $value) {
            if (! isset($data[$property])) {
                $data[$property] = $value;
            }
        }

        return $data;
    }

    public function scheduleForInsert($object)
    {
        $oid = spl_object_hash($object);
        if (isset($this->identifiers[$oid])) {
            return;
        }

        $class = $this->cmf->getMetadataFor(get_class($object));
        $id    = $this->idHandler->getIdentifier($class, $object);

        if (! $id) {
            throw new \RuntimeException('Trying to persist entity that has no id.');
        }

        $idHash = $this->idHandler->hash($id);

        if (isset($this->identityMap[$class->name][$idHash])) {
            throw new \RuntimeException('Object with ID already exists.');
        }

        $this->scheduledInsertions[$oid]          = $object;
        $this->identityMap[$class->name][$idHash] = $object;
    }

    public function scheduleForDelete($object)
    {
        $oid = spl_object_hash($object);
        if (! isset($this->identifiers[$oid])) {
            throw new \RuntimeException(
                'Object scheduled for deletion is not managed. Only managed objects can be deleted.'
            );
        }
        $this->scheduledDeletions[$oid] = $object;
    }

    private function processIdentityMap()
    {
        foreach ($this->identityMap as $className => $entities) {
            foreach ($entities as $object) {
                $hash = spl_object_hash($object);

                if (isset($this->scheduledInsertions[$hash])) {
                    continue;
                }

                $metadata  = $this->cmf->getMetadataFor($className);
                $changeSet = $this->computeChangeSet($metadata, $object);

                if ($changeSet) {
                    $this->storageDriver->update($metadata->storageName, $this->identifiers[$hash], $changeSet);

                    if ($this->storageDriver->supportsPartialUpdates()) {
                        $this->originalData[$hash] = array_merge($this->originalData[$hash], $changeSet);
                    } else {
                        $this->originalData[$hash] = $changeSet;
                    }
                }
            }
        }
    }

    private function processInsertions()
    {
        foreach ($this->scheduledInsertions as $object) {
            $class = $this->cmf->getMetadataFor(get_class($object));
            $id    = $this->idHandler->getIdentifier($class, $object);
            $id    = $this->idConverter->serialize($class, $id);

            if (! $id) {
                throw new \RuntimeException('Trying to persist entity that has no id.');
            }

            $data              = $this->getObjectSnapshot($class, $object);

            $oid    = spl_object_hash($object);
            $idHash = $this->idHandler->hash($id);

            $this->storageDriver->insert($class->storageName, $id, $data);

            $this->originalData[$oid]                 = $data;
            $this->identifiers[$oid]                  = $id;
            $this->identityMap[$class->name][$idHash] = $object;
        }
    }

    private function processDeletions()
    {
        foreach ($this->scheduledDeletions as $object) {
            $class  = $this->cmf->getMetadataFor(get_class($object));
            $oid    = spl_object_hash($object);
            $id     = $this->identifiers[$oid];
            $idHash = $this->idHandler->hash($id);

            $this->storageDriver->delete($class->storageName, $id);

            unset($this->identifiers[$oid], $this->originalData[$oid], $this->identityMap[$class->name][$idHash]);
        }
    }

    public function commit()
    {
        $this->processIdentityMap();
        $this->processInsertions();
        $this->processDeletions();

        $this->scheduledInsertions = [];
        $this->scheduledDeletions  = [];
    }

    public function clear()
    {
        $this->scheduledInsertions = [];
        $this->scheduledDeletions  = [];
        $this->identifiers         = [];
        $this->originalData        = [];
        $this->identityMap         = [];
    }
}
