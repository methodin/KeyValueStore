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

namespace Doctrine\KeyValueStore\Mapping;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriver;

class AnnotationDriver implements MappingDriver
{
    /**
     * Doctrine common annotations reader.
     *
     * @var AnnotationReader
     */
    private $reader;

    /**
     * Constructor with required dependencies.
     *
     * @param $reader AnnotationReader Doctrine common annotations reader.
     */
    public function __construct(AnnotationReader $reader)
    {
        $this->reader = $reader;
    }

    /**
     * Loads the metadata for the specified class into the provided container.
     *
     * @param string        $className
     * @param ClassMetadata $metadata
     */
    public function loadMetadataForClass($className, ClassMetadata $metadata)
    {
        $class = $metadata->getReflectionClass();
        if (! $class) {
            // this happens when running annotation driver in combination with
            // static reflection services. This is not the nicest fix
            $class = new \ReflectionClass($metadata->name);
        }

        $embeddableAnnot = $this->reader->getClassAnnotation($class, 'Doctrine\KeyValueStore\Mapping\Annotations\Embeddable');

        $entityAnnot = $this->reader->getClassAnnotation($class, 'Doctrine\KeyValueStore\Mapping\Annotations\Entity');
        if (! $entityAnnot && ! $embeddableAnnot) {
            throw new \InvalidArgumentException($metadata->name . ' is not a valid key-value-store entity.');
        }

        if ($embeddableAnnot) {
            $metadata->embeddable = true;
            return;
        }

        $metadata->storageName = $entityAnnot->storageName;
        // Evaluate annotations on properties/fields
        foreach ($class->getProperties() as $property) {
            $idAnnot        = $this->reader->getPropertyAnnotation(
                $property,
                'Doctrine\KeyValueStore\Mapping\Annotations\Id'
            );
            $transientAnnot = $this->reader->getPropertyAnnotation(
                $property,
                'Doctrine\KeyValueStore\Mapping\Annotations\Transient'
            );
            $embeddedAnnot = $this->reader->getPropertyAnnotation(
                $property,
                'Doctrine\KeyValueStore\Mapping\Annotations\Embedded'
            );
            if ($idAnnot) {
                $metadata->mapIdentifier($property->getName());
            } elseif ($transientAnnot) {
                $metadata->skipTransientField($property->getName());
            } else {
                $data = ['fieldName' => $property->getName()];
                if ($embeddedAnnot) {
                    $data['embedded'] = $embeddedAnnot->target;
                }
                $metadata->mapField($data);
            }
        }
    }

    /**
     * Gets the names of all mapped classes known to this driver.
     *
     * @return array The names of all mapped classes known to this driver.
     */
    public function getAllClassNames()
    {
    }

    /**
     * Whether the class with the specified name should have its metadata loaded.
     * This is only the case if it is either mapped as an Entity or a
     * MappedSuperclass.
     *
     * @param string $className
     *
     * @return bool
     */
    public function isTransient($className)
    {
        return false;
    }
}
