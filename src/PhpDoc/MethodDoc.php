<?php

namespace danog\PhpDoc\PhpDoc;

use danog\PhpDoc\PhpDoc;
use phpDocumentor\Reflection\DocBlock\Description;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * Method documentation builder.
 *
 * @internal
 */
class MethodDoc extends GenericDoc
{
    private Return_ $return;
    private string $psalmReturn;
    private array $params = [];
    private array $psalmParams = [];
    /**
     * Constructor.
     *
     * @param PhpDoc $phpDocBuilder
     * @param ReflectionFunctionAbstract $method
     */
    public function __construct(PhpDoc $phpDocBuilder, ReflectionFunctionAbstract $method)
    {
        $this->builder = $phpDocBuilder;
        $this->name = $method->getName();
        $doc = $method->getDocComment() ?: '/** */';
        $doc = $this->builder->getFactory()->create($doc);

        parent::__construct($doc, $method instanceof ReflectionMethod ? $method->getDeclaringClass() : $method);

        $order = [];
        $optional = [];

        $docReflection = "/**\n";
        foreach ($method->getParameters() as $param) {
            $order []= $param->getName();
            $opt = $param->isOptional() && !$param->isVariadic();
            $default = '';
            if ($opt) {
                if ($default = $param->getDefaultValueConstantName()) {
                    $default = "\\$default";
                } else {
                    $default = \str_replace([PHP_EOL, 'array (', ')'], ['', '[', ']'], \var_export($param->getDefaultValue(), true));
                }
            }
            $optional[$param->getName()] = [$opt, $default];
            $type = (string) ($param->getType() ?? 'mixed');
            $variadic = $param->isVariadic() ? '...' : '';
            $docReflection .= " * @param $type $variadic\$".$param->getName()."\n";
        }
        $docReflection .= ' * @return '.($method->getReturnType() ?? 'mixed')."\n*/";
        $docReflection = $this->builder->getFactory()->create($docReflection);

        $params = [];
        $psalmParams = [];

        foreach ([...$doc->getTags(), ...$docReflection->getTags()] as $tag) {
            if ($tag instanceof Param && !isset($params[$tag->getVariableName()])) {
                $params[$tag->getVariableName()] = [
                    $tag->getType(),
                    $tag->getDescription(),
                    $tag->isVariadic(),
                    $optional[$tag->getVariableName()]
                ];
            } elseif ($tag instanceof Return_ && !isset($this->return) && $this->name !== '__construct') {
                $this->return = $tag;
            } elseif ($tag instanceof Generic && $tag->getName() === 'psalm-return') {
                $this->psalmReturn = $tag;
            } elseif ($tag instanceof Generic && $tag->getName() === 'psalm-param') {
                $desc = $tag->getDescription();
                $dollar = \strrpos($desc, '$');
                $type = \substr($tag, 0, $dollar-1);
                $description = \substr($tag, $dollar+1);
                $description .= ' ';
                [$varName, $description] = \explode(" ", $description, 2);
                if (!$description && isset($params[$varName])) {
                    $description = (string) $params[$varName][1];
                } else {
                    $description = new Description($description);
                }
                $psalmParams[$varName] = [
                    $type,
                    $description,
                    $optional[$varName]
                ];
            }
        }

        foreach ($order as $param) {
            $this->params[$param] = $params[$param];
            if (isset($psalmParams[$param])) {
                $this->psalmParams[$param] = $psalmParams[$param];
            }
        }

        foreach ($this->params as &$param) {
            if (isset($param[0])) {
                $param[0] = $this->resolveTypeAlias($param[0]);
            }
        }
        foreach ($this->psalmParams as &$param) {
            if (isset($param[0])) {
                $param[0] = $this->resolveTypeAlias($param[0]);
            }
        }
        if (isset($this->psalmReturn)) {
            $this->psalmReturn = $this->resolveTypeAlias($this->psalmReturn);
        }
    }

    /**
     * Get method signature.
     *
     * @return string
     */
    public function getSignature(): string
    {
        $sig = $this->name;
        $sig .= "(";
        foreach ($this->params as $var => [$type, $description, $variadic, [$optional, $default]]) {
            $sig .= $type.' ';
            if ($variadic) {
                $sig .= '...';
            }
            $sig .= "$".$var;
            if ($optional) {
                $sig .= " = ".$default;
            }
            $sig .= ', ';
        }
        $sig = \trim($sig, ', ');
        $sig .= ')';
        if (isset($this->return)) {
            $sig .= ': ';
            $sig .= $this->resolveTypeAlias($this->return);
        }
        return $sig;
    }
    /**
     * Get method signature link.
     *
     * @return string
     */
    public function getSignatureLink(): string
    {
        $sig = $this->getSignature();
        $sigLink = $this->getSignatureAnchor();
        return "[`$sig`](#$sigLink)";
    }
    /**
     * Get method signature link.
     *
     * @return string
     */
    public function getSignatureAnchor(): string
    {
        $sig = $this->getSignature();
        $sigLink = \strtolower($sig);
        $sigLink = \preg_replace('/[^\w ]+/', ' ', $sigLink);
        $sigLink = \preg_replace('/ +/', ' ', $sigLink);
        $sigLink = \str_replace(' ', '-', $sigLink);
        return \trim($sigLink, '-');
    }
    /**
     * Generate markdown for method.
     *
     * @return string
     */
    public function format(?string $namespace = null): string
    {
        $sig = '### `'.$this->getSignature()."`";
        $sig .= "\n\n";
        $sig .= $this->title;
        $sig .= "\n";
        $sig .= \str_replace("\n", "  \n", $this->description);
        $sig .= "\n";
        if ($this->psalmParams || $this->params) {
            $sig .= "\nParameters:\n\n";
            foreach ($this->params as $name => [$type, $description, $variadic]) {
                $variadic = $variadic ? '...' : '';
                $sig .= "* `$variadic\$$name`: `$type` $description  \n";
                if (isset($this->psalmParams[$name])) {
                    [$psalmType] = $this->psalmParams[$name];
                    $psalmType = \trim(\str_replace("\n", "\n  ", $psalmType));

                    $sig .= "  Full type:\n";
                    $sig .= "  ```\n";
                    $sig .= "  $psalmType\n";
                    $sig .= "  ```\n";
                }
            }
            $sig .= "\n";
        }
        if (isset($this->return) && $this->return->getDescription() && $this->return->getDescription()->render()) {
            $sig .= "\nReturn value: ".$this->return->getDescription()."\n";
        }
        if (isset($this->psalmReturn)) {
            $sig .= "\nFully typed return value:\n```\n".$this->psalmReturn."\n```";
        }
        $sig .= $this->seeAlso($namespace ?? $this->namespace);
        $sig .= "\n";

        return $sig;
    }
}
