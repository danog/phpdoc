<?php

namespace danog\PhpDoc\PhpDoc;

use danog\PhpDoc\PhpDoc;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Description;
use phpDocumentor\Reflection\DocBlock\Tags\Author;
use phpDocumentor\Reflection\DocBlock\Tags\Deprecated;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use phpDocumentor\Reflection\DocBlock\Tags\Reference\Fqsen;
use phpDocumentor\Reflection\DocBlock\Tags\Reference\Url;
use phpDocumentor\Reflection\DocBlock\Tags\See;
use phpDocumentor\Reflection\Fqsen as ReflectionFqsen;
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
    protected Description $description;
    /**
     * See also array.
     *
     * @var array<string, See>
     */
    protected array $seeAlso = [];
    /**
     * Authors.
     *
     * @var Author[]
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
     * @param DocBlock $doc
     * @param ReflectionClass|ReflectionFunction $reflectionClass
     */
    public function __construct(DocBlock $doc, $reflectionClass)
    {
        $empty = [];
        $this->className = $reflectionClass->getName();
        $this->resolvedClassName = $this->builder->resolveTypeAlias($this->className, $this->className, $empty);
        $this->namespace = \str_replace('/', '\\', \dirname(\str_replace('\\', '/', $this->className)));
        $this->title = $doc->getSummary();
        $this->description = $doc->getDescription();
        $tags = $doc->getTags();

        $this->authors = $this->builder->getAuthors();
        foreach ($tags as $tag) {
            if ($tag instanceof Author) {
                $this->authors []= $tag;
            }
            if ($tag instanceof Deprecated) {
                $this->ignore = true;
                break;
            }
            if ($tag instanceof Generic && $tag->getName() === 'internal') {
                $this->ignore = true;
                break;
            }
            if ($tag instanceof See) {
                $this->seeAlso[$tag->getReference()->__toString()] = $tag;
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

        $empty = [];
        $seeAlso = '';
        foreach ($this->seeAlso as $see) {
            $ref = $see->getReference();
            if ($ref instanceof Fqsen) {
                $ref = (string) $ref;
                $ref = $this->builder->resolveTypeAlias($this->className, $ref, $empty);

                $to = \explode("\\", $ref.".md");
                if (\count($to) === 2 || !$this->builder->hasClass($ref)) {
                    $seeAlso .= "* `$ref`\n";
                    continue;
                }

                \array_shift($to);
                \array_unshift($to, ...\array_fill(0, \count($namespace), '..'));
                $relPath = $to;
                $path = \implode('/', $relPath);

                if ($path === '../../../danog/MadelineProto/EventHandler.md') {
                    \var_dump($namespace);
                }

                if (!$desc = $see->getDescription()) {
                    if ($desc = $this->builder->getTitle($ref)) {
                        $desc = "`$ref`: $desc";
                    } else {
                        $desc = $ref;
                    }
                }
                $seeAlso .= "* [$desc]($path)\n";
            }
            if ($ref instanceof Url) {
                $desc = $see->getDescription() ?: $ref;
                $seeAlso .= "* [$desc]($ref)\n";
            }
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
                $this->seeAlso[$type] = new See(new Fqsen(new ReflectionFqsen($type)));
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
