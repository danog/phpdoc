<?php

namespace danog\PhpDoc\PhpDoc;

use danog\PhpDoc\PhpDoc;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PropertyTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Class documentation builder.
 *
 * @internal
 */
class ClassDoc extends GenericDoc
{
    /**
     * Properties.
     *
     * @var array<string, array>
     */
    private array $properties = [];
    /**
     * Methods.
     *
     * @var array<string, MethodDoc>
     */
    private array $methods = [];
    /**
     * Constants.
     */
    private array $constants = [];
    public function __construct(PhpDoc $builder, ReflectionClass $reflectionClass)
    {
        $this->builder = $builder;
        $this->name = $reflectionClass->getName();
        $doc = $reflectionClass->getDocComment() ?: '/** */';
        $doc = $this->builder->parse($doc);

        parent::__construct($doc, $reflectionClass);

        $docReflection = "/**\n";
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PUBLIC & ~ReflectionProperty::IS_STATIC) as $property) {
            $type = $property->getType() ?? 'mixed';
            $type = (string) $type;
            $name = $property->getName();
            $comment = '';
            foreach ($this->builder->parse($property->getDocComment() ?: '/** */')->getTags() as $tag) {
                if ($tag->name === '@var') {
                    $tag = $tag->value;
                    \assert($tag instanceof VarTagValueNode);
                    $type = (string) $tag->type;
                    $comment = $tag->description;
                    break;
                }
                if ($tag->name === 'internal') {
                    continue 2;
                }
            }
            if (!$comment) {
                $comment = \trim($property->getDocComment() ?: '', "\n/* ");
            }
            $docReflection .= " * @property $type \$$name $comment\n";
        }
        $docReflection .= " */\n";
        $docReflection = $this->builder->parse($docReflection);

        $tags = \array_merge($docReflection->getTags(), $doc->getTags());
        foreach ($tags as $tag) {
            if ($tag->name === 'property') {
                $tag = $tag->value;
                \assert($tag instanceof PropertyTagValueNode);
                /** @psalm-suppress InvalidPropertyAssignmentValue */
                $this->properties[$tag->propertyName] = [
                    (string) $tag->type,
                    $tag->description
                ];
            }
        }
        foreach ($reflectionClass->getConstants() as $key => $value) {
            $refl = new ReflectionClassConstant($reflectionClass->getName(), $key);
            if (!$refl->isPublic()) {
                continue;
            }
            $description = '';
            if ($refl->getDocComment()) {
                if ($this->builder->shouldIgnore($refl->getDeclaringClass()->getName())) {
                    continue;
                }
                $docConst = $this->builder->parse($refl->getDocComment());
                if ($docConst->getTagsByName('internal')) {
                    continue;
                }
                foreach ($docConst->children as $node) {
                    if ($node instanceof PhpDocTextNode) {
                        $description .= $node->text."\n";
                    }
                }
            }
            $description = \trim($description);
            $this->constants[$key] = [
                $value,
                $description
            ];
        }


        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (\str_starts_with($method->getName(), '__') && $method->getName() !== '__construct') {
                continue;
            }
            $this->methods[$method->getName()] = new MethodDoc($this->builder, $method);
        }

        $this->methods = \array_filter($this->methods, fn (MethodDoc $doc): bool => !$doc->shouldIgnore());
    }

    /**
     * Generate markdown for class.
     *
     * @return string
     */
    public function format(?string $namespace = null): string
    {
        $init = parent::format();
        if ($this->constants) {
            $init .= "\n";
            $init .= "## Constants\n";
            foreach ($this->constants as $name => [, $description]) {
                $description = \trim($description);
                $description = \str_replace("\n", "\n  ", $description);
                $init .= "* `{$this->className}::$name`: $description\n";
                $init .= "\n";
            }
        }
        if ($this->properties) {
            $init .= "## Properties\n";
            foreach ($this->properties as $name => [$type, $description]) {
                $init .= "* `\$$name`: `$type` $description";
                $init .= "\n";
            }
        }
        if ($this->methods) {
            $init .= "\n";
            $init .= "## Method list:\n";
            foreach ($this->methods as $method) {
                $init .= "* ".$method->getSignatureLink()."\n";
            }
            $init .= "\n";
            $init .= "## Methods:\n";
            foreach ($this->methods as $method) {
                $init .= $method->format($namespace ?? $this->namespace);
                $init .= "\n";
            }
        }
        $init .= "---\n";
        $init .= "Generated by [danog/phpdoc](https://phpdoc.daniil.it)\n";
        return $init;
    }
}
