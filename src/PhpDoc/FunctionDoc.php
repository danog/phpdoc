<?php

namespace danog\PhpDoc\PhpDoc;

use ReflectionFunction;

class FunctionDoc extends MethodDoc
{
    public function __construct(PhpDoc $builder, ReflectionFunction $reflectionClass)
    {
        $this->builder = $builder;
        $this->nameGenericDoc = $reflectionClass->getName();
        $doc = $reflectionClass->getDocComment();
        if (!$doc) {
            \fprintf(STDERR, $reflectionClass->getName()." has no PHPDOC\n");
            $this->ignore = true;
            return;
        }
        $doc = $this->builder->getFactory()->create($doc);

        parent::__construct($builder, $reflectionClass);
    }
}
