<?php

namespace danog\PhpDoc\PhpDoc;

use ReflectionFunction;

/**
 * Function documentation builder.
 *
 * @internal
 */
class FunctionDoc extends MethodDoc
{
    /**
     * Constructor.
     *
     * @param PhpDoc $builder
     * @param ReflectionFunction $reflectionClass
     */
    public function __construct(PhpDoc $builder, ReflectionFunction $reflectionClass)
    {
        $this->builder = $builder;
        $this->name = $reflectionClass->getName();
        $doc = $reflectionClass->getDocComment();
        if (!$doc) {
            \fprintf(STDERR, $reflectionClass->getName()." has no PHPDOC".PHP_EOL);
            $this->ignore = true;
            return;
        }
        $doc = $this->builder->getFactory()->create($doc);

        parent::__construct($builder, $reflectionClass);
    }
}
