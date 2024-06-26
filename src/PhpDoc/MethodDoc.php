<?php

namespace danog\PhpDoc\PhpDoc;

use danog\PhpDoc\PhpDoc;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * Method documentation builder.
 *
 * @internal
 */
class MethodDoc extends GenericDoc
{
    private string $return;
    private string $returnDescription;
    private array $params = [];
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
        $doc = $this->builder->parse($doc);

        parent::__construct($doc, $method instanceof ReflectionMethod ? $method->getDeclaringClass() : $method);

        $order = [];
        $optional = [];
        $params = [];

        foreach ($method->getParameters() as $param) {
            $order []= '$'.$param->getName();
            $opt = $param->isOptional() && !$param->isVariadic();
            $default = '';
            if ($opt) {
                if ($default = $param->getDefaultValueConstantName()) {
                    $default = "\\$default";
                } else {
                    $default = \str_replace([PHP_EOL, 'array (', ')'], ['', '[', ']'], \var_export($param->getDefaultValue(), true));
                }
            }
            $optional['$'.$param->getName()] = [$opt, $default];
            $type = (string) ($param->getType() ?? 'mixed');
            $params['$'.$param->getName()] = [
                $type,
                '',
                $param->isVariadic(),
                $optional['$'.$param->getName()]
            ];
        }

        foreach (['@param', '@psalm-param', '@phpstan-param'] as $t) {
            foreach ($doc->getParamTagValues($t) as $tag) {
                $params[$tag->parameterName] ??= [
                    $tag->type,
                    $tag->description,
                    $tag->isVariadic,
                    $optional[$tag->parameterName]
                ];
                $params[$tag->parameterName][0] = $tag->type;
                $params[$tag->parameterName][1] = $tag->description;
            }
        }
        if ($this->name !== '__construct') {
            foreach (['@return', '@psalm-return', '@phpstan-return'] as $t) {
                foreach ($doc->getReturnTagValues($t) as $tag) {
                    $this->return = (string) $tag->type;
                    $this->returnDescription = $tag->description;
                }
            }
        }
        if (!isset($this->return) && $ret = $method->getReturnType()) {
            $this->return = (string) $ret;
            $this->returnDescription = '';
        }

        foreach ($order as $param) {
            $this->params[$param] = $params[$param];
        }

        foreach ($this->params as &$param) {
            if (isset($param[0])) {
                $param[0] = $this->resolveTypeAlias($param[0]);
            }
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
            $sig .= $var;
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
        return $this->name;
    }
    /**
     * Generate markdown for method.
     *
     * @return string
     */
    public function format(?string $namespace = null): string
    {
        $sig = '### <a name="'.$this->name.'"></a> `'.$this->getSignature()."`";
        $sig .= "\n\n";
        $sig .= $this->title;
        $sig .= "\n";
        $sig .= \str_replace("\n", "  \n", $this->description);
        $sig .= "\n";
        if ($this->params) {
            $sig .= "\nParameters:\n\n";
            foreach ($this->params as $name => [$type, $description, $variadic]) {
                $variadic = $variadic ? '...' : '';
                $sig .= "* `$variadic$name`: `$type` $description  \n";
            }
            $sig .= "\n";
        }
        if (isset($this->returnDescription) && $this->returnDescription) {
            $sig .= "\nReturn value: ".$this->returnDescription."\n";
        }
        $sig .= $this->seeAlso($namespace ?? $this->namespace);
        $sig .= "\n";

        return $sig;
    }
}
