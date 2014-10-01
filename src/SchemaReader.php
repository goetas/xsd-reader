<?php
namespace Goetas\XML\XSDReader;

use DOMDocument;
use DOMElement;
use Goetas\XML\XSDReader\Utils\UrlUtils;
use Goetas\XML\XSDReader\Schema\Schema;
use Goetas\XML\XSDReader\Schema\Element\Element;
use Goetas\XML\XSDReader\Schema\Attribute\Attribute;
use Goetas\XML\XSDReader\Schema\Type\ComplexType;
use Goetas\XML\XSDReader\Schema\Type\SimpleType;
use Goetas\XML\XSDReader\Schema\Type\Type;
use Goetas\XML\XSDReader\Schema\Type\BaseComplexType;
use Goetas\XML\XSDReader\Schema\Item;
use Goetas\XML\XSDReader\Exception\TypeException;
use Goetas\XML\XSDReader\Exception\IOException;
use Goetas\XML\XSDReader\Schema\Attribute\Group as AttributeGroup;
use Goetas\XML\XSDReader\Schema\Element\Group;
use Goetas\XML\XSDReader\Schema\Element\ElementContainer;
use Goetas\XML\XSDReader\Schema\Type\ComplexTypeSimpleContent;
use Goetas\XML\XSDReader\Schema\Element\ElementDef;
use Goetas\XML\XSDReader\Schema\Inheritance\Restriction;
use Goetas\XML\XSDReader\Schema\Inheritance\Extension;
use Goetas\XML\XSDReader\Schema\Exception\TypeNotFoundException;
use Goetas\XML\XSDReader\Schema\Element\ElementRef;
use Goetas\XML\XSDReader\Schema\Attribute\AttributeRef;
use Goetas\XML\XSDReader\Schema\Element\ElementItem;
use Goetas\XML\XSDReader\Schema\Attribute\AttributeDef;

class SchemaReader
{

    const XSD_NS = "http://www.w3.org/2001/XMLSchema";

    const XML_NS = "http://www.w3.org/XML/1998/namespace";

    private $loadedFiles = array();

    private $globalSchemas = array();

    private $knowLocationSchemas = array();

    private static $globalSchemaInfo = array(
        self::XML_NS => 'http://www.w3.org/2001/xml.xsd',
        self::XSD_NS => 'http://www.w3.org/2001/XMLSchema.xsd'
    );

    public function __construct()
    {
        $this->addKnownSchemaLocation('http://www.w3.org/2001/xml.xsd', __DIR__ . '/Resources/xml.xsd');
        $this->addKnownSchemaLocation('http://www.w3.org/2001/XMLSchema.xsd', __DIR__ . '/Resources/XMLSchema.xsd');
    }

    public function addKnownSchemaLocation($remote, $local)
    {
        $this->knowLocationSchemas[$remote] = $local;
    }

    private function loadAttributeGroup(Schema $schema, DOMElement $node)
    {
        $attGroup = new AttributeGroup($schema, $node->getAttribute("name"));
        $attGroup->setDoc($this->getDocumentation($node));
        $schema->addAttributeGroup($attGroup);

        return function () use($schema, $node, $attGroup)
        {
            foreach ($node->childNodes as $childNode) {
                switch ($childNode->localName) {
                    case 'attribute':
                        if ($childNode->hasAttribute("ref")) {
                            $attribute = $this->findSomething('findAttribute', $schema, $node, $childNode->getAttribute("ref"));
                        } else {
                            $attribute = $this->loadAttribute($schema, $childNode);
                        }
                        $attGroup->addAttribute($attribute);
                        break;
                    case 'attributeGroup':

                        $attribute = $this->findSomething('findAttributeGroup', $schema, $node, $childNode->getAttribute("ref"));
                        $attGroup->addAttribute($attribute);
                        break;
                }
            }
        };
    }

    private function loadAttribute(Schema $schema, DOMElement $node)
    {
        $attribute = new Attribute($schema, $node->getAttribute("name"));
        $attribute->setDoc($this->getDocumentation($node));
        $this->fillItem($attribute, $node);

        if ($node->hasAttribute("nillable")) {
            $attribute->setNil($node->getAttribute("nillable") == "true");
        }
        if ($node->hasAttribute("form")) {
            $attribute->setQualified($node->getAttribute("form") == "qualified");
        }
        if ($node->hasAttribute("use")) {
            $attribute->setUse($node->getAttribute("use"));
        }
        return $attribute;
    }


    private function loadAttributeDef(Schema $schema, DOMElement $node)
    {
        $attribute = new AttributeDef($schema, $node->getAttribute("name"));

        $schema->addAttribute($attribute);

        return function () use($attribute, $schema, $node)
        {
            $this->fillItem($attribute, $node);
        };
    }

    /**
     * @param DOMElement $node
     * @return string
     */
    private function getDocumentation(DOMElement $node)
    {
        $doc = '';
        foreach ($node->childNodes as $childNode) {
            if ($childNode->localName == "annotation") {
                foreach ($childNode->childNodes as $subChildNode) {
                    if ($subChildNode->localName == "documentation") {
                        $doc .= ($subChildNode->nodeValue);
                    }
                }
            }
        }
        return $doc;
    }

    /**
     *
     * @param Schema $schema
     * @param DOMElement $node
     * @param Schema $parent
     * @return array
     */
    private function schemaNode(Schema $schema, DOMElement $node, Schema $parent = null)
    {
        $schema->setDoc($this->getDocumentation($node));

        if ($node->hasAttribute("targetNamespace")) {
            $schema->setTargetNamespace($node->getAttribute("targetNamespace"));
        } elseif ($parent) {
            $schema->setTargetNamespace($parent->getTargetNamespace());
        }
        $schema->setElementsQualification(! $node->hasAttribute("elementFormDefault") || $node->getAttribute("elementFormDefault") == "qualified");
        $schema->setAttributesQualification(! $node->hasAttribute("attributeFormDefault") || $node->getAttribute("attributeFormDefault") == "qualified");
        $schema->setDoc($this->getDocumentation($node));
        $functions = array();

        foreach ($node->childNodes as $childNode) {
            switch ($childNode->localName) {
                case 'include':
                case 'import':
                    $functions[] = $this->loadImport($schema, $childNode);
                    break;
                case 'element':
                    $functions[] = $this->loadElementDef($schema, $childNode);
                    break;
                case 'attribute':
                    $functions[] = $this->loadAttributeDef($schema, $childNode);
                    break;
                case 'attributeGroup':
                    $functions[] = $this->loadAttributeGroup($schema, $childNode);
                    break;
                case 'group':
                    $functions[] = $this->loadGroup($schema, $childNode);
                    break;
                case 'complexType':
                    $functions[] = $this->loadComplexType($schema, $childNode);
                    break;
                case 'simpleType':
                    $functions[] = $this->loadSimpleType($schema, $childNode);
                    break;
            }
        }

        return $functions;
    }

    private function loadElement(Schema $schema, DOMElement $node)
    {
        $element = new Element($schema, $node->getAttribute("name"));
        $element->setDoc($this->getDocumentation($node));

        $this->fillItem($element, $node);

        if ($node->hasAttribute("maxOccurs")) {
            $element->setMax($node->getAttribute("maxOccurs") == "unbounded" ? - 1 : (int)$node->getAttribute("maxOccurs"));
        }
        if ($node->hasAttribute("minOccurs")) {
            $element->setMin((int)$node->getAttribute("minOccurs"));
        }
        if ($node->hasAttribute("nillable")) {
            $element->setNil($node->getAttribute("nillable") == "true");
        }
        if ($node->hasAttribute("form")) {
            $element->setQualified($node->getAttribute("form") == "qualified");
        }
        return $element;
    }

    private function loadElementRef(ElementDef $referencedElement, DOMElement $node)
    {
        $element = new ElementRef($referencedElement);
        $element->setDoc($this->getDocumentation($node));

        if ($node->hasAttribute("maxOccurs")) {
            $element->setMax($node->getAttribute("maxOccurs") == "unbounded" ? - 1 : (int)$node->getAttribute("maxOccurs"));
        }
        if ($node->hasAttribute("minOccurs")) {
            $element->setMin((int)$node->getAttribute("minOccurs"));
        }
        if ($node->hasAttribute("nillable")) {
            $element->setNil($node->getAttribute("nillable") == "true");
        }
        if ($node->hasAttribute("form")) {
            $element->setQualified($node->getAttribute("form") == "qualified");
        }

        return $element;
    }


    private function loadAttributeRef(AttributeDef $referencedAttribiute, DOMElement $node)
    {
        $attribute = new AttributeRef($referencedAttribiute);
        $attribute->setDoc($this->getDocumentation($node));

        if ($node->hasAttribute("nillable")) {
            $attribute->setNil($node->getAttribute("nillable") == "true");
        }
        if ($node->hasAttribute("form")) {
            $attribute->setQualified($node->getAttribute("form") == "qualified");
        }
        if ($node->hasAttribute("use")) {
            $attribute->setUse($node->getAttribute("use"));
        }
        return $attribute;
    }

    private function loadSequence(ElementContainer $elementContainer, DOMElement $node)
    {
        foreach ($node->childNodes as $childNode) {

            switch ($childNode->localName) {
                case 'element':
                    if ($childNode->hasAttribute("ref")) {
                        $referencedElement = $this->findSomething('findElement', $elementContainer->getSchema(), $node, $childNode->getAttribute("ref"));
                        $element = $this->loadElementRef($referencedElement, $childNode);
                    } else {
                        $element = $this->loadElement($elementContainer->getSchema(), $childNode);
                    }
                    $elementContainer->addElement($element);
                    break;
                case 'group':
                    $element = $this->findSomething('findGroup', $elementContainer->getSchema(), $node, $childNode->getAttribute("ref"));
                    $elementContainer->addElement($element);
                    break;
            }
        }
    }

    private function loadGroup(Schema $schema, DOMElement $node)
    {
        $type = new Group($schema, $node->getAttribute("name"));
        $type->setDoc($this->getDocumentation($node));
        $schema->addGroup($type);

        return function () use($type, $node)
        {
            foreach ($node->childNodes as $childNode) {
                switch ($childNode->localName) {
                    case 'sequence':
                    case 'choice':
                        $this->loadSequence($type, $childNode);
                        break;
                }
            }
        };
    }

    private function loadComplexType(Schema $schema, DOMElement $node, $callback = null)
    {
        $isSimple = false;

        foreach ($node->childNodes as $childNode) {
            if ($childNode->localName === "simpleContent") {
                $isSimple = true;
                break;
            }
        }

        $type = $isSimple ? new ComplexTypeSimpleContent($schema, $node->getAttribute("name")) : new ComplexType($schema, $node->getAttribute("name"));

        $type->setDoc($this->getDocumentation($node));
        if ($node->getAttribute("name")) {
            $schema->addType($type);
        }

        return function () use($type, $node, $schema, $callback)
        {

            $this->fillTypeNode($type, $node);

            foreach ($node->childNodes as $childNode) {
                switch ($childNode->localName) {
                    case 'sequence':
                    case 'choice':
                        $this->loadSequence($type, $childNode);
                        break;
                    case 'attribute':
                        if ($childNode->hasAttribute("ref")) {
                            $referencedAttribute = $this->findSomething('findAttribute', $schema, $node, $childNode->getAttribute("ref"));
                            $attribute = $this->loadAttributeRef($referencedAttribute, $childNode);
                        } else {
                            $attribute = $this->loadAttribute($schema, $childNode);
                        }

                        $type->addAttribute($attribute);
                        break;
                    case 'attributeGroup':
                        $attribute = $this->findSomething('findAttributeGroup', $schema, $node, $childNode->getAttribute("ref"));
                        $type->addAttribute($attribute);
                        break;
                }
            }

            if ($callback) {
                call_user_func($callback, $type);
            }
        };
    }

    private function loadSimpleType(Schema $schema, DOMElement $node, $callback = null)
    {
        $type = new SimpleType($schema, $node->getAttribute("name"));
        $type->setDoc($this->getDocumentation($node));
        if ($node->getAttribute("name")) {
            $schema->addType($type);
        }

        return function () use($type, $node, $callback)
        {
            $this->fillTypeNode($type, $node);

            foreach ($node->childNodes as $childNode) {
                switch ($childNode->localName) {
                    case 'union':
                        $this->loadUnion($type, $childNode);
                        break;
                }
            }

            if ($callback) {
                call_user_func($callback, $type);
            }
        };
    }

    private function loadUnion(SimpleType $type, DOMElement $node)
    {
        if ($node->hasAttribute("memberTypes")) {
            $types = preg_split('/\s+/', $node->getAttribute("memberTypes"));
            foreach ($types as $typeName) {
                $type->addUnion($this->findSomething('findType', $type->getSchema(), $node, $typeName));
            }
        }
        $addCallback = function ($unType) use($type)
        {
            $type->addUnion($unType);
        };

        foreach ($node->childNodes as $childNode) {
            switch ($childNode->localName) {
                case 'simpleType':
                    call_user_func($this->loadSimpleType($type->getSchema(), $childNode, $addCallback));
                    break;
            }
        }
    }

    private function fillTypeNode(Type $type, DOMElement $node)
    {
        foreach ($node->childNodes as $childNode) {
            switch ($childNode->localName) {
                case 'restriction':
                    $this->loadRestriction($type, $childNode);
                    break;
                case 'extension':
                    $this->loadExtension($type, $childNode);
                    break;
                case 'simpleContent':
                case 'complexContent':
                    $this->fillTypeNode($type, $childNode);
                    break;
            }
        }
    }

    private function loadExtension(BaseComplexType $type, DOMElement $node)
    {
        $extension = new Extension();
        $type->setExtension($extension);

        if ($node->hasAttribute("base")) {
            $parent = $this->findSomething('findType', $type->getSchema(), $node, $node->getAttribute("base"));
            $extension->setBase($parent);
        }

        foreach ($node->childNodes as $childNode) {
            switch ($childNode->localName) {
                case 'sequence':
                case 'choice':
                    $this->loadSequence($type, $childNode);
                    break;
                case 'attribute':
                    if ($childNode->hasAttribute("ref")) {
                        $attribute = $this->findSomething('findAttribute', $type->getSchema(), $node, $childNode->getAttribute("ref"));
                    } else {
                        $attribute = $this->loadAttribute($type->getSchema(), $childNode);
                    }
                    $type->addAttribute($attribute);
                    break;
                case 'attributeGroup':
                    $attribute = $this->findSomething('findAttributeGroup', $type->getSchema(), $node, $childNode->getAttribute("ref"));
                    $type->addAttribute($attribute);
                    break;
            }
        }
    }

    private function loadRestriction(Type $type, DOMElement $node)
    {
        $restriction = new Restriction();
        $type->setRestriction($restriction);
        if ($node->hasAttribute("base")) {
            $restrictedType = $this->findSomething('findType', $type->getSchema(), $node, $node->getAttribute("base"));
            $restriction->setBase($restrictedType);
        } else {
            $addCallback = function ($restType) use($restriction)
            {
                $restriction->setBase($restType);
            };

            foreach ($node->childNodes as $childNode) {
                switch ($childNode->localName) {
                    case 'simpleType':
                        call_user_func($this->loadSimpleType($type->getSchema(), $childNode, $addCallback));
                        break;
                }
            }
        }
        foreach ($node->childNodes as $childNode) {
            if (in_array($childNode->localName,
                [
                    'enumeration',
                    'pattern',
                    'length',
                    'minLength',
                    'maxLength',
                    'minInclusve',
                    'maxInclusve',
                    'minExclusve',
                    'maxEXclusve'
                ], true)) {
                $restriction->addCheck($childNode->localName,
                    [
                        'value' => $childNode->getAttribute("value"),
                        'doc' => $this->getDocumentation($childNode)
                    ]);
            }
        }
    }

    private static function splitParts(DOMElement $node, $typeName)
    {
        $namespace = null;
        $prefix = null;
        $name = $typeName;
        if (strpos($typeName, ':') !== false) {
            list ($prefix, $name) = explode(':', $typeName);
            $namespace = $node->lookupNamespaceURI($prefix);
        }
        return array(
            $name,
            $namespace,
            $prefix
        );
    }

    /**
     *
     * @param string $finder
     * @param Schema $schema
     * @param DOMElement $node
     * @param string $typeName
     * @throws TypeException
     * @return ElementItem|Group|AttributeItem|AttribiuteGroup|Type
     */
    private function findSomething($finder, Schema $schema, DOMElement $node, $typeName)
    {
        list ($name, $namespace) = self::splitParts($node, $typeName);

        $namespace = $namespace ?: $schema->getTargetNamespace();

        try {
            return $schema->$finder($name, $namespace);
        } catch (TypeNotFoundException $e) {
            throw new TypeException(sprintf("Can't find %s named {%s}#%s, at line %d in %s ", strtolower(substr($finder, 4)), $namespace, $name, $node->getLineNo(), $node->ownerDocument->documentURI), 0, $e);
        }
    }

    private function loadElementDef(Schema $schema, DOMElement $node)
    {
        $element = new ElementDef($schema, $node->getAttribute("name"));
        $schema->addElement($element);

        return function () use ($element, $node) {
            $this->fillItem($element, $node);
        };
    }

    private function fillItem(Item $element, DOMElement $node)
    {
        $element->setIsAnonymousType(! $node->hasAttribute("type"));

        if ($element->isAnonymousType()) {

            $addCallback = function ($type) use($element) {
                $element->setType($type);
            };
            foreach ($node->childNodes as $childNode) {
                switch ($childNode->localName) {
                    case 'complexType':
                        call_user_func($this->loadComplexType($element->getSchema(), $childNode, $addCallback));
                        break;
                    case 'simpleType':
                        call_user_func($this->loadSimpleType($element->getSchema(), $childNode, $addCallback));
                        break;
                }
            }
        } else {
            $type = $this->findSomething('findType', $element->getSchema(), $node, $node->getAttribute("type"));
            $element->setType($type);
        }
    }

    private function loadImport(Schema $schema, DOMElement $node)
    {
        $file = UrlUtils::resolveRelativeUrl($node->ownerDocument->documentURI, $node->getAttribute("schemaLocation"));
        if ($node->hasAttribute("namespace") && in_array($node->getAttribute("namespace"), array_keys(self::$globalSchemaInfo), true)){
            return function ()
            {
            };
        }elseif (isset($this->loadedFiles[$file])) {
            $schema->addSchema($this->loadedFiles[$file]);
            return function () {
            };
        }

        if (!$node->getAttribute("namespace")){
            $this->loadedFiles[$file] = $newSchema = $schema;
        }else{
            $this->loadedFiles[$file] = $newSchema = new Schema($file);
        }



        foreach ($this->globalSchemas as $globaSchemaNS => $globaSchema) {
            $newSchema->addSchema($globaSchema, $globaSchemaNS);
        }

        $xml = $this->getDOM(isset($this->knowLocationSchemas[$file])?$this->knowLocationSchemas[$file]:$file);


        $callbacks = $this->schemaNode($newSchema, $xml->documentElement, $schema);

        if ($node->getAttribute("namespace")){
            $schema->addSchema($newSchema);
        }


        return function () use($callbacks)
        {
            foreach ($callbacks as $callback) {
                call_user_func($callback);
            }
        };
    }

    private function addGlobalSchemas(Schema $rootSchema)
    {
        if (! $this->globalSchemas) {

            $callbacks = array();
            foreach (self::$globalSchemaInfo as $namespace => $uri) {
                $this->globalSchemas[$namespace] = $schema = new Schema($uri);

                $xml = $this->getDOM($this->knowLocationSchemas[$uri]);
                $callbacks = array_merge($callbacks, $this->schemaNode($schema, $xml->documentElement));
            }

            $this->globalSchemas[self::XSD_NS]->addType(new SimpleType($this->globalSchemas[self::XSD_NS], "anySimpleType"));

            $this->globalSchemas[self::XML_NS]->addSchema($this->globalSchemas[self::XSD_NS], self::XSD_NS);
            $this->globalSchemas[self::XSD_NS]->addSchema($this->globalSchemas[self::XML_NS], self::XML_NS);

            foreach ($callbacks as $callback) {
                $callback();
            }
        }

        foreach ($this->globalSchemas as $globalSchema) {
            $rootSchema->addSchema($globalSchema, $globalSchema->getTargetNamespace());
        }
    }



    /**
     * @return \Goetas\XML\XSDReader\Schema\Schema
     */
    public function readNode(\DOMNode $node, $file = 'schema.xsd')
    {
        $this->loadedFiles[$file] = $rootSchema = new Schema($file);

        $this->addGlobalSchemas($rootSchema);
        $callbacks = $this->schemaNode($rootSchema, $node);

        foreach ($callbacks as $callback) {
            call_user_func($callback);
        }

        return $rootSchema;
    }


    /**
     * @return \Goetas\XML\XSDReader\Schema\Schema
     */
    public function readString($content, $file = 'schema.xsd')
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        if (! $xml->loadXML($content)) {
            throw new IOException("Can't load the schema");
        }
        $xml->documentURI = $file;

        return $this->readNode($xml->documentElement, $file);
    }
    /**
     * @return \Goetas\XML\XSDReader\Schema\Schema
     */
    public function readFile($file)
    {
        $xml = $this->getDOM($file);
        return $this->readNode($xml->documentElement, $file);
    }

    /**
     * @param string $file
     * @throws IOException
     * @return \DOMDocument
     */
    private function getDOM($file)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        if (! $xml->load($file)) {
            throw new IOException("Can't load the file $file");
        }
        return $xml;
    }
}
