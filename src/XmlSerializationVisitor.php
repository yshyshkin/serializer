<?php

/*
 * Copyright 2016 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\Serializer;

use JMS\Serializer\Accessor\AccessorStrategyInterface;
use JMS\Serializer\Exception\RuntimeException;
use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\Metadata\PropertyMetadata;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;

/**
 * XmlSerializationVisitor.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class XmlSerializationVisitor extends AbstractVisitor implements SerializationVisitorInterface
{
    use LegacyTrait;
    /**
     * @var \DOMDocument
     */
    private $document;

    private $navigator;
    private $defaultRootName = 'result';
    private $defaultRootNamespace;
    private $defaultVersion = '1.0';
    private $defaultEncoding = 'UTF-8';
    private $stack;
    private $metadataStack;
    private $currentNode;
    private $currentMetadata;
    private $hasValue;
    private $nullWasVisited;
    private $objectMetadataStack;

    /** @var boolean */
    private $formatOutput;

    public function __construct(PropertyNamingStrategyInterface $namingStrategy, AccessorStrategyInterface $accessorStrategy = null)
    {
        parent::__construct($namingStrategy, $accessorStrategy);
        $this->objectMetadataStack = new \SplStack;
        $this->formatOutput = true;
    }

    public function setDefaultRootName($name, $namespace = null)
    {
        $this->defaultRootName = $name;
        $this->defaultRootNamespace = $namespace;
    }

    /**
     * @return boolean
     */
    public function hasDefaultRootName()
    {
        return 'result' === $this->defaultRootName;
    }

    public function setDefaultVersion($version)
    {
        $this->defaultVersion = $version;
    }

    public function setDefaultEncoding($encoding)
    {
        $this->defaultEncoding = $encoding;
    }

    public function initialize(GraphNavigatorInterface $navigator):void
    {
        $this->navigator = $navigator;
        $this->stack = new \SplStack;
        $this->metadataStack = new \SplStack;

        $this->currentNode = null;
        $this->nullWasVisited = false;

        $version = null;
        $encoding = null;
        $this->document = new \DOMDocument($version ?: $this->defaultVersion, $encoding ?: $this->defaultEncoding);
        $this->document->formatOutput = $this->isFormatOutput();
    }

    public function createRoot(ClassMetadata $metadata = null, $rootName = null, $rootNamespace = null)
    {
        if ($metadata !== null && !empty($metadata->xmlRootName)) {
            $rootName = $metadata->xmlRootName;
            $rootNamespace = $metadata->xmlRootNamespace ?: $this->getClassDefaultNamespace($metadata);
        } else {
            $rootName = $rootName ?: $this->defaultRootName;
            $rootNamespace = $rootNamespace ?: $this->defaultRootNamespace;
        }

        if ($rootNamespace) {
            $rootNode = $this->document->createElementNS($rootNamespace, $rootName);
        } else {
            $rootNode = $this->document->createElement($rootName);
        }
        $this->document->appendChild($rootNode);
        $this->setCurrentNode($rootNode);

        return $rootNode;
    }

    public function serializeNull(TypeDefinition $type, SerializationContext $context)
    {
        $node = $this->document->createAttribute('xsi:nil');
        $node->value = 'true';
        $this->nullWasVisited = true;

        return $node;
    }

    public function serializeString($data, TypeDefinition $type, SerializationContext $context)
    {
        $doCData = null !== $this->currentMetadata ? $this->currentMetadata->xmlElementCData : true;

        return $doCData ? $this->document->createCDATASection($data) : $this->document->createTextNode((string)$data);
    }

    public function serializeSimpleString($data, TypeDefinition $type, SerializationContext $context)
    {
        return $this->document->createTextNode((string)$data);
    }

    public function serializeBoolean($data, TypeDefinition $type, SerializationContext $context)
    {
        return $this->document->createTextNode($data ? 'true' : 'false');
    }

    public function serializeInteger($data, TypeDefinition $type, SerializationContext $context)
    {
        return $this->serializeNumeric($data, $type);
    }

    public function serializeFloat($data, TypeDefinition $type, SerializationContext $context)
    {
        return $this->serializeNumeric($data, $type);
    }

    public function serializeArray($data, TypeDefinition $type, SerializationContext $context)
    {
        if ($this->currentNode === null) {
            $this->createRoot();
        }

        $entryName = (null !== $this->currentMetadata && null !== $this->currentMetadata->xmlEntryName) ? $this->currentMetadata->xmlEntryName : 'entry';
        $keyAttributeName = (null !== $this->currentMetadata && null !== $this->currentMetadata->xmlKeyAttribute) ? $this->currentMetadata->xmlKeyAttribute : null;
        $namespace = (null !== $this->currentMetadata && null !== $this->currentMetadata->xmlEntryNamespace) ? $this->currentMetadata->xmlEntryNamespace : null;

        foreach ($data as $k => $v) {

            if (null === $v && $context->shouldSerializeNull() !== true) {
                continue;
            }

            $tagName = (null !== $this->currentMetadata && $this->currentMetadata->xmlKeyValuePairs && $this->isElementNameValid($k)) ? $k : $entryName;

            $entryNode = $this->createElement($tagName, $namespace);
            $this->currentNode->appendChild($entryNode);
            $this->setCurrentNode($entryNode);

            if (null !== $keyAttributeName) {
                $entryNode->setAttribute($keyAttributeName, (string)$k);
            }

            if (null !== $node = $this->navigator->acceptData($v, $this->findElementType($type), $context)) {
                $this->currentNode->appendChild($node);
            }

            $this->revertCurrentNode();
        }
    }

    public function startSerializingObject(ClassMetadata $metadata, $data, TypeDefinition $type, SerializationContext $context):void
    {
        $this->objectMetadataStack->push($metadata);

        if ($this->currentNode === null) {
            $this->createRoot($metadata);
        }

        $this->addNamespaceAttributes($metadata, $this->currentNode);

        $this->hasValue = false;
    }

    public function serializeProperty(PropertyMetadata $metadata, $object, SerializationContext $context):void
    {
        $v = $this->accessor->getValue($object, $metadata);

        if (null === $v && $context->shouldSerializeNull() !== true) {
            return;
        }

        if ($metadata->xmlAttribute) {
            $this->setCurrentMetadata($metadata);
            $node = $this->navigator->acceptData($v, $metadata->getTypeDefinition(), $context);
            $this->revertCurrentMetadata();

            if (!$node instanceof \DOMCharacterData) {
                throw new RuntimeException(sprintf('Unsupported value for XML attribute for %s. Expected character data, but got %s.', $metadata->name, json_encode($v)));
            }
            $attributeName = $this->namingStrategy->translateName($metadata);
            $this->setAttributeOnNode($this->currentNode, $attributeName, $node->nodeValue, $metadata->xmlNamespace);

            return;
        }

        if (($metadata->xmlValue && $this->currentNode->childNodes->length > 0)
            || (!$metadata->xmlValue && $this->hasValue)
        ) {
            throw new RuntimeException(sprintf('If you make use of @XmlValue, all other properties in the class must have the @XmlAttribute annotation. Invalid usage detected in class %s.', $metadata->class));
        }

        if ($metadata->xmlValue) {
            $this->hasValue = true;

            $this->setCurrentMetadata($metadata);
            $node = $this->navigator->acceptData($v, $metadata->getTypeDefinition(), $context);
            $this->revertCurrentMetadata();

            if (!$node instanceof \DOMCharacterData) {
                throw new RuntimeException(sprintf('Unsupported value for property %s::$%s. Expected character data, but got %s.', $metadata->reflection->class, $metadata->reflection->name, is_object($node) ? get_class($node) : gettype($node)));
            }

            $this->currentNode->appendChild($node);

            return;
        }

        if ($metadata->xmlAttributeMap) {
            if (!is_array($v)) {
                throw new RuntimeException(sprintf('Unsupported value type for XML attribute map. Expected array but got %s.', gettype($v)));
            }

            foreach ($v as $key => $value) {
                $this->setCurrentMetadata($metadata);
                $node = $this->navigator->acceptData($value, null, $context);
                $this->revertCurrentMetadata();

                if (!$node instanceof \DOMCharacterData) {
                    throw new RuntimeException(sprintf('Unsupported value for a XML attribute map value. Expected character data, but got %s.', json_encode($v)));
                }

                $this->setAttributeOnNode($this->currentNode, $key, $node->nodeValue, $metadata->xmlNamespace);
            }

            return;
        }

        if ($addEnclosingElement = !$this->isInLineCollection($metadata) && !$metadata->inline) {
            $elementName = $this->namingStrategy->translateName($metadata);

            $namespace = null !== $metadata->xmlNamespace
                ? $metadata->xmlNamespace
                : $this->getClassDefaultNamespace($this->objectMetadataStack->top());

            $element = $this->createElement($elementName, $namespace);
            $this->currentNode->appendChild($element);
            $this->setCurrentNode($element);
        }

        $this->setCurrentMetadata($metadata);

        if (null !== $node = $this->navigator->acceptData($v, $metadata->getTypeDefinition(), $context)) {
            $this->currentNode->appendChild($node);
        }

        $this->revertCurrentMetadata();

        if ($addEnclosingElement) {
            $this->revertCurrentNode();

            if ($this->isElementEmpty($element) && ($this->isSkippableCollection($metadata) || $v === null || $context->isVisiting($v))) {
                $this->currentNode->removeChild($element);
            }
        }

        $this->hasValue = false;
    }

    private function isInLineCollection(PropertyMetadata $metadata)
    {
        return $metadata->xmlCollection && $metadata->xmlCollectionInline;
    }

    private function isSkippableCollection(PropertyMetadata $metadata)
    {
        return $metadata->xmlCollection && $metadata->xmlCollectionSkipWhenEmpty;
    }

    private function isElementEmpty(\DOMElement $element)
    {
        return !$element->hasChildNodes() && !$element->hasAttributes();
    }

    public function endSerializingObject(ClassMetadata $metadata, $data, TypeDefinition $type, SerializationContext $context)
    {
        $this->objectMetadataStack->pop();
    }

    public function getSerializationResult($node)
    {
        if ($this->document->documentElement === null) {
            if ($node instanceof \DOMElement) {
                $this->document->appendChild($node);
            } else {
                $this->createRoot();
                if ($node) {
                    $this->document->documentElement->appendChild($node);
                }
            }
        }

        if ($this->nullWasVisited) {
            $this->document->documentElement->setAttributeNS(
                'http://www.w3.org/2000/xmlns/',
                'xmlns:xsi',
                'http://www.w3.org/2001/XMLSchema-instance'
            );
        }
        return $this->document->saveXML();
    }

    public function getCurrentNode()
    {
        return $this->currentNode;
    }

    public function getCurrentMetadata()
    {
        return $this->currentMetadata;
    }

    public function getDocument()
    {
        return $this->document;
    }

    public function setCurrentMetadata(PropertyMetadata $metadata)
    {
        $this->metadataStack->push($this->currentMetadata);
        $this->currentMetadata = $metadata;
    }

    public function setCurrentNode(\DOMNode $node)
    {
        $this->stack->push($this->currentNode);
        $this->currentNode = $node;
    }

    public function setCurrentAndRootNode(\DOMNode $node)
    {
        $this->setCurrentNode($node);
        $this->document->appendChild($node);
    }

    public function revertCurrentNode()
    {
        return $this->currentNode = $this->stack->pop();
    }

    public function revertCurrentMetadata()
    {
        return $this->currentMetadata = $this->metadataStack->pop();
    }

    private function serializeNumeric($data, TypeDefinition $type)
    {
        return $this->document->createTextNode((string)$data);
    }

    /**
     * Checks that the name is a valid XML element name.
     *
     * @param string $name
     *
     * @return boolean
     */
    private function isElementNameValid($name)
    {
        return $name && false === strpos($name, ' ') && preg_match('#^[\pL_][\pL0-9._-]*$#ui', $name);
    }

    /**
     * Adds namespace attributes to the XML root element
     *
     * @param \JMS\Serializer\Metadata\ClassMetadata $metadata
     * @param \DOMElement $element
     */
    private function addNamespaceAttributes(ClassMetadata $metadata, \DOMElement $element)
    {
        foreach ($metadata->xmlNamespaces as $prefix => $uri) {
            $attribute = 'xmlns';
            if ($prefix !== '') {
                $attribute .= ':' . $prefix;
            } elseif ($element->namespaceURI === $uri) {
                continue;
            }
            $element->setAttributeNS('http://www.w3.org/2000/xmlns/', $attribute, $uri);
        }
    }

    private function createElement($tagName, $namespace = null)
    {
        if (null === $namespace) {
            return $this->document->createElement($tagName);
        }
        if ($this->currentNode->isDefaultNamespace($namespace)) {
            return $this->document->createElementNS($namespace, $tagName);
        }
        if (!($prefix = $this->currentNode->lookupPrefix($namespace)) && !($prefix = $this->document->lookupPrefix($namespace))) {
            $prefix = 'ns-' . substr(sha1($namespace), 0, 8);
        }
        return $this->document->createElementNS($namespace, $prefix . ':' . $tagName);
    }

    private function setAttributeOnNode(\DOMElement $node, $name, $value, $namespace = null)
    {
        if (null !== $namespace) {
            if (!$prefix = $node->lookupPrefix($namespace)) {
                $prefix = 'ns-' . substr(sha1($namespace), 0, 8);
            }
            $node->setAttributeNS($namespace, $prefix . ':' . $name, $value);
        } else {
            $node->setAttribute($name, $value);
        }
    }

    private function getClassDefaultNamespace(ClassMetadata $metadata)
    {
        return (isset($metadata->xmlNamespaces['']) ? $metadata->xmlNamespaces[''] : null);
    }

    /**
     * @return bool
     */
    public function isFormatOutput()
    {
        return $this->formatOutput;
    }

    /**
     * @param bool $formatOutput
     */
    public function setFormatOutput($formatOutput)
    {
        $this->formatOutput = (boolean)$formatOutput;
    }

    /**
     * @deprecated
     */
    public function getResult()
    {
        return $this->getSerializationResult( $this->document->documentElement);
    }

    /**
     * @deprecated
     */
    public function visitSimpleString($data, array $type, Context $context)
    {
        return $this->serializeSimpleString($data, TypeDefinition::fromArray($type), $context);
    }
}
