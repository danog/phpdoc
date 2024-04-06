<?php

namespace danog\PhpDoc\PhpDoc;

use danog\PhpDoc\PhpDoc;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTextNode;
use ReflectionClass;
use ReflectionFunction;

/**
 * Generic documentation builder.
 *
 * @internal
 */
abstract class GenericDoc
{
    /**
     * Builder instance.
     */
    protected PhpDoc $builder;
    /**
     * Name.
     */
    protected string $name;
    /**
     * Title.
     */
    protected string $title;
    /**
     * Description.
     */
    protected string $description;
    /**
     * See also array.
     *
     * @var array<string, GenericTagValueNode>
     */
    protected array $seeAlso = [];
    /**
     * Authors.
     *
     * @var string[]
     */
    protected array $authors;
    /**
     * Ignore this class.
     */
    protected bool $ignore = false;
    /**
     * Class name.
     */
    protected string $className;
    /**
     * Class namespace.
     */
    protected string $namespace;
    /**
     * Fully qualified class name.
     */
    private string $resolvedClassName;
    /**
     * Constructor.
     *
     * @param ReflectionClass|ReflectionFunction $reflectionClass
     */
    public function __construct(PhpDocNode $doc, $reflectionClass)
    {
        $empty = [];
        $this->className = $reflectionClass->getName();
        $this->resolvedClassName = $this->builder->resolveTypeAlias($this->className, $this->className, $empty);
        $this->namespace = \str_replace('/', '\\', \dirname(\str_replace('\\', '/', $this->className)));
        $description = '';
        foreach ($doc->children as $child) {
            if ($child instanceof PhpDocTextNode) {
                $description .= $child->text."\n";
            }
        }
        [$this->title, $this->description] = \explode("\n", \trim($description)."\n", 2);
        $tags = $doc->getTags();

        $this->authors = $this->builder->getAuthors();
        foreach ($tags as $tag) {
            if ($tag->name === '@author') {
                $tag = $tag->value;
                \assert($tag instanceof GenericTagValueNode);
                $this->authors []= $tag->value;
                continue;
            }
            if ($tag->name === '@deprecated' || $tag->name === '@internal') {
                $this->ignore = true;
                break;
            }
            if ($tag->name === '@see') {
                $tag = $tag->value;
                \assert($tag instanceof GenericTagValueNode);
                $this->seeAlso[$tag->value] = $tag;
            }
        }
        $this->authors = \array_unique($this->authors);
    }

    /**
     * Get see also list.
     *
     * @return string
     */
    public function seeAlso(string $namespace): string
    {
        $namespace = \explode('\\', $namespace);

        $seeAlso = '';
        foreach ($this->seeAlso as $see) {
            $seeAlso .= "* ".$see->value."\n";
        }
        if ($seeAlso) {
            $seeAlso = "\n#### See also: \n$seeAlso\n\n";
        }
        return $seeAlso;
    }
    /**
     * Generate markdown.
     *
     * @return string
     */
    public function format(?string $namespace = null): string
    {
        $authors = '';
        foreach ($this->authors as $author) {
            $authors .= "> Author: $author  \n";
        }
        $seeAlso = $this->seeAlso($namespace ?? $this->namespace);
        $frontMatter = $this->builder->getFrontMatter(
            [
                'title' => "{$this->name}: {$this->title}",
                'description' => $this->description
            ]
        );
        $index = '';
        $count = \count(\explode('\\', $this->resolvedClassName)) - 2;
        $index .= \str_repeat('../', $count);
        $index .= 'index.md';
        return <<<EOF
        ---
        $frontMatter
        ---
        # `$this->name`
        [Back to index]($index)

        $authors  
        
        $this->title  

        $this->description
        $seeAlso

        EOF;
    }
    /**
     * Resolve type alias.
     *
     * @param string $type Type
     * @return string
     */
    public function resolveTypeAlias(string $type): string
    {
        $resolved = [];
        $result = $this->builder->resolveTypeAlias($this->className, $type, $resolved);
        foreach ($resolved as $type) {
            if (PhpDoc::isScalar($type)) {
                continue;
            }
            if ($type === $this->resolvedClassName) {
                continue;
            }
            if (\str_contains($type, ' ')) {
                continue;
            }
            try {
                $this->seeAlso[$type] = new GenericTagValueNode($type);
            } catch (\Throwable $e) {
            }
        }
        return $result;
    }

    /**
     * Whether we should not store this class.
     *
     * @return boolean
     */
    public function shouldIgnore(): bool
    {
        return $this->ignore;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get title.
     *
     * @return string
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Get fully qualified class name.
     *
     * @return string
     */
    public function getResolvedClassName(): string
    {
        return $this->resolvedClassName;
    }
}
