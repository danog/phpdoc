<?php
/**
 * PhpDocBuilder module.
 *
 * This file is part of PhpDoc.
 * PhpDoc is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * PhpDoc is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with PhpDoc.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2020 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 *
 * @link https://phpdoc.daniil.it PhpDoc documentation
 */

namespace danog\PhpDoc;

use danog\ClassFinder\ClassFinder;
use danog\PhpDoc\PhpDoc\ClassDoc;
use danog\PhpDoc\PhpDoc\FunctionDoc;
use danog\PhpDoc\PhpDoc\GenericDoc;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use ReflectionClass;
use ReflectionFunction;
use Symfony\Component\Yaml\Escaper;

/**
 * Documentation builder.
 *
 * @internal
 */
class PhpDoc
{
    /**
     * Namespace.
     */
    private string $namespace;
    /**
     * Scan mode.
     */
    private int $mode = ClassFinder::ALLOW_ALL | ClassFinder::RECURSIVE_MODE;
    /**
     * PHPDOC parser.
     */
    private PhpDocParser $parser;
    /**
     * Lexer.
     */
    private Lexer $lexer;
    /**
     * Authors.
     */
    private array $authors = [];
    /**
     * Classes/interfaces/traits to ignore.
     *
     * @var ?callable
     * @psalm-var null|callable(class-string)
     */
    private $ignore;
    /**
     * Output directory.
     */
    private string $output;
    /**
     * Project name.
     */
    private string $name = 'PHPDOC';
    /**
     * Project description.
     */
    private string $description = 'PHPDOC documentation';
    /**
     * Project front matter.
     *
     * @var array<string, string>
     */
    private array $frontMatter = [];
    /**
     * Index front matter.
     *
     * @var array<string, string>
     */
    private array $indexFrontMatter = [];
    /**
     * Use map.
     *
     * @var array<class-string, array<class-string, class-string>>
     */
    private array $useMap = [];
    /**
     * Title map.
     *
     * @var array<class-string, string>
     */
    private array $titleMap = [];
    /**
     * Class map.
     *
     * @var array<class-string, bool>
     */
    private array $classMap = [];
    /**
     * Create docblock builder.
     *
     * @param string $namespace Namespace (defaults to package namespace)
     *
     * @return self
     */
    public static function fromNamespace(string $namespace = ''): self
    {
        return new self($namespace);
    }
    /**
     * Constructor.
     *
     * @param string $namespace
     * @param int    $mode
     */
    private function __construct(string $namespace)
    {
        $this->lexer = new Lexer();
        $constExprParser = new ConstExprParser();
        $typeParser = new TypeParser($constExprParser);
        $this->parser = new PhpDocParser(
            $typeParser,
            $constExprParser,
            textBetweenTagsBelongsToDescription: true
        );
        $this->namespace = $namespace;

        $appRoot = new \danog\ClassFinder\AppConfig;
        $appRoot = $appRoot->getAppRoot();
        $appRoot .= "/composer.json";
        $json = \json_decode(\file_get_contents($appRoot), true);
        $authors = $json['authors'] ?? [];

        $this->name = $json['name'] ?? '';
        $this->description = $json['description'] ?? '';
        foreach ($authors as $author) {
            $this->authors []= "{$author['name']} <{$author['email']}>";
        }

        if (!$this->namespace) {
            $namespaces = \array_keys($json['autoload']['psr-4']);
            $this->namespace = $namespaces[0];
            foreach ($namespaces as $namespace) {
                if (\strlen($namespace) && \strlen($namespace) < \strlen($this->namespace)) {
                    $this->namespace = $namespace;
                }
            }
        }
    }
    /**
     * Set filter to ignore certain classes.
     *
     * @param callable $ignore
     *
     * @psalm-param callable(class-string) $ignore
     *
     * @return self
     */
    public function setFilter(callable $ignore): self
    {
        $this->ignore = $ignore;

        return $this;
    }
    /**
     * Set output directory.
     *
     * @param string $output Output directory
     *
     * @return self
     */
    public function setOutput(string $output): self
    {
        $this->output = $output;

        return $this;
    }
    /**
     * Resolve type aliases.
     *
     * @return void
     */
    public function resolveAliases(): void
    {
        $classList = ClassFinder::getClassesInNamespace($this->namespace, $this->mode);
        foreach ($classList as $class) {
            $this->addTypeAliases($class);
        }
    }
    /**
     * Run documentor.
     *
     * @return self
     */
    public function run(): self
    {
        $this->resolveAliases();
        $classList = ClassFinder::getClassesInNamespace($this->namespace, $this->mode);
        $namespaces = [];
        foreach ($this->useMap as $orig => $aliases) {
            $class = \str_replace('\\', '/', $orig);
            $namespace = \dirname($class);
            $namespaces[$namespace]['\\'.\basename($class)] = $orig;
            $namespaces[$namespace][\basename($class)] = $orig;
        }
        foreach ($this->useMap as $class => &$aliases) {
            $class = \str_replace('\\', '/', $class);
            $namespace = \dirname($class);
            $aliases = \array_merge($namespaces[$namespace], $aliases);
        }
        $final = [];
        $traits = '';
        $classes = '';
        $abstract = '';
        $interfaces = '';
        $functions = '';
        foreach ($classList as $class) {
            if ($this->ignore && $this->shouldIgnore($class)) {
                continue;
            }
            $reflectionClass = \function_exists($class)
                ? new ReflectionFunction($class)
                : new ReflectionClass($class);

            $class = $reflectionClass instanceof ReflectionFunction
                ? new FunctionDoc($this, $reflectionClass)
                : new ClassDoc($this, $reflectionClass);

            if ($class->shouldIgnore()) {
                continue;
            }
            $final[$class->getName()] = $class;
            $this->titleMap[$class->getResolvedClassName()] = $class->getTitle();
            $this->classMap[$class->getResolvedClassName()] = true;

            $line = '';
            $line .= $class->getResolvedClassName();
            if ($class->getTitle()) {
                $line .= ": ".$class->getTitle();
            }
            $path = $class->getResolvedClassName();
            $path = \str_replace('\\', '/', \substr($path, 1));
            $path .= '.md';
            if ($class instanceof FunctionDoc) {
                $functions .= "* [$line]($path)\n";
            } elseif ($reflectionClass->isTrait()) {
                $traits .= "* [$line]($path)\n";
            } elseif ($reflectionClass->isAbstract()) {
                $abstract .= "* [$line]($path)\n";
            } elseif ($reflectionClass->isInterface()) {
                $interfaces .= "* [$line]($path)\n";
            } else {
                $classes .= "* [$line]($path)\n";
            }
        }

        foreach ($final as $class) {
            $this->generate($class);
        }


        $functions = $functions ? "## Functions\n$functions" : '';
        $traits = $traits ? "## Traits\n$traits" : '';
        $abstract = $abstract ? "## Abstract classes\n$abstract" : '';
        $interfaces = $interfaces ? "## Interfaces\n$interfaces" : '';
        $classes = $classes ? "## Classes\n$classes" : '';

        $description = \explode("\n", $this->description);
        $description = $description[0] ?? '';

        $frontMatter = $this->getIndexFrontMatter(
            [
                'description' => $description,
                'title' => $this->name,
            ]
        );

        $index = <<<EOF
        ---
        $frontMatter
        ---
        # `$this->name`

        $description

        $functions
        $interfaces
        $abstract
        $classes
        $traits

        ---
        Generated by [danog/phpdoc](https://phpdoc.daniil.it).  
        EOF;

        $fName = $this->output;
        $fName .= DIRECTORY_SEPARATOR;
        $fName .= 'index.md';

        $handle = \fopen(self::createDir($fName), 'w+');
        \fwrite($handle, $index);
        \fclose($handle);

        return $this;
    }
    /**
     * Get title for class.
     *
     * @param string $class
     * @return string
     */
    public function getTitle(string $class): string
    {
        return $this->titleMap[$class] ?? '';
    }
    /**
     * Check if we are generating docs for this class.
     *
     * @param string $class
     * @return boolean
     */
    public function hasClass(string $class): bool
    {
        return isset($this->classMap[$class]);
    }
    /**
     * Resolve type alias.
     *
     * @internal
     *
     * @param string   $fromClass Class from where this function is called
     * @param string   $name      Name to resolve
     * @param string[] $resolved  Resolved names
     *
     * @psalm-param class-string $fromClass Class from where this function is called
     * @psalm-param class-string $name      Name to resolve
     *
     * @return string
     */
    public function resolveTypeAlias(string $fromClass, string $name, array &$resolved): string
    {
        if (\str_ends_with($name, '[]')) {
            return $this->resolveTypeAlias($fromClass, \substr($name, 0, -2), $resolved)."[]";
        }
        if ($name[0] === '(' && $name[\strlen($name) - 1] === ')') {
            $name = $this->resolveTypeAlias($fromClass, \substr($name, 1, -1), $resolved);
            return "($name)";
        }
        if (\count($split = self::splitOnWithoutParenthesis('|', $name)) > 1) {
            foreach ($split as &$name) {
                $name = $this->resolveTypeAlias($fromClass, $name, $resolved);
            }
            return \implode('|', $split);
        }
        if (\str_starts_with($name, 'callable(')) {
            $name = $this->resolveTypeAlias($fromClass, \substr($name, 9, -1), $resolved);
            return "callable($name)";
        }
        if (\str_starts_with($name, 'array{')) {
            $new = '';
            $split = self::splitOnWithoutParenthesis(',', \substr($name, 6, -1));
            foreach ($split as $key => $var) {
                if (\preg_match('/^([^:]+): *(.+)/s', $var, $matches)) {
                    [, $key, $var] = $matches;
                }
                $new .= "$key: ".$this->resolveTypeAlias($fromClass, $var, $resolved).", ";
            }
            $new = \substr($new, 0, -2);
            return 'array{'.$new.'}';
        }
        if (\preg_match("/([^<]+)[<](.+)[>]$/s", $name, $matches)) {
            [, $main, $template] = $matches;
            $newTemplate = '';
            foreach (self::splitOnWithoutParenthesis(',', $template) as $arg) {
                $newTemplate .= $this->resolveTypeAlias($fromClass, $arg, $resolved).', ';
            }
            $template = \substr($newTemplate, 0, -2);
            $main = $this->resolveTypeAlias($fromClass, $main, $resolved);
            return "$main<$template>";
        }
        if ($name[0] === '?') {
            return "?".$this->resolveTypeAlias($fromClass, \substr($name, 1), $resolved);
        }
        $res = $this->useMap[$fromClass][$name] ?? $name;
        if ($res[0] !== '\\' && !self::isScalar($res) && \str_contains($res, '\\')) {
            $res = "\\$res";
        }
        $resolved []= $res;
        return $res;
    }
    /**
     * Split string, without considering separators inside parenthesis.
     *
     * @param string $separator
     * @param string $type
     * @return array
     */
    private static function splitOnWithoutParenthesis(string $separator, string $type): array
    {
        $args = [''];
        $openCount = 0;
        for ($x = 0; $x < \strlen($type); $x++) {
            $char = $type[$x];
            if ($char === '(' || $char === '<' || $char === '{') {
                $openCount++;
            } elseif ($char === ')' || $char === '>' || $char === '}') {
                $openCount--;
            } elseif ($char === $separator && !$openCount) {
                $args []= '';
                continue;
            }
            $args[\count($args)-1] .= $char;
        }
        return \array_map('trim', $args);
    }
    /**
     * Check if type is scalar.
     *
     * @param string $type
     * @return boolean
     */
    public static function isScalar(string $type): bool
    {
        return \in_array($type, ['string', 'int', 'integer', 'float', 'bool', 'boolean', 'void', 'mixed', 'object', 'callable', 'iterable', 'class-string', 'array', 'array-key', 'static', 'self', 'null', 'true', 'false', 'list']);
    }
    /**
     * Add type alias.
     *
     * @param string $class
     *
     * @psalm-param class-string $class
     *
     * @return void
     */
    private function addTypeAliases(string $class)
    {
        $reflectionClass = \function_exists($class)
            ? new ReflectionFunction($class)
            : new ReflectionClass($class);
        $payload = \file_get_contents($reflectionClass->getFileName());
        \preg_match_all("/use *(function)? +(.*?)(?: +as +(.+))? *;/", $payload, $matches, PREG_SET_ORDER|PREG_UNMATCHED_AS_NULL);
        $this->useMap[$class] ??= [];
        foreach ($matches as [, $function, $import, $alias]) {
            $import = "\\$import";
            $alias ??= \basename(\str_replace('\\', '/', $import));
            $this->useMap[$class][$alias] = $import;
            $this->useMap[$class]['\\'.$alias] = $import;
        }

        if ($reflectionClass instanceof ReflectionClass) {
            \array_map($this->addTypeAliases(...), $reflectionClass->getTraitNames());
        }
    }
    /**
     * Create directory recursively.
     *
     * @param string $file
     * @return string
     */
    private static function createDir(string $file): string
    {
        $dir = \dirname($file);
        if (!\file_exists($dir)) {
            self::createDir($dir);
            \mkdir($dir);
        }
        return $file;
    }

    /**
     * Generate documentation for class.
     *
     * @param GenericDoc $class Class
     *
     * @return void
     */
    private function generate(GenericDoc $class): void
    {
        $name = $class->getName();
        $fName = $this->output;
        $fName .= DIRECTORY_SEPARATOR;
        $fName .= \str_replace('\\', DIRECTORY_SEPARATOR, $name);
        $fName .= '.md';

        $class = $class->format();

        $handle = \fopen(self::createDir($fName), 'w+');
        \fwrite($handle, $class);
        \fclose($handle);
    }

    /**
     * Parse phpdoc.
     *
     * @internal
     */
    public function parse(string $phpdoc): PhpDocNode
    {
        return $this->parser->parse(new TokenIterator($this->lexer->tokenize($phpdoc)));
    }

    /**
     * Whether should ignore trait/class/interface.
     *
     * @return bool
     */
    public function shouldIgnore(string $class): bool
    {
        return $this->ignore ? !($this->ignore)($class) : false;
    }

    /**
     * Get authors.
     *
     * @return string[]
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }

    /**
     * Set authors.
     *
     * @param string[] $authors Authors
     *
     * @return self
     */
    public function setAuthors(array $authors): self
    {
        $this->authors = $authors;

        return $this;
    }

    /**
     * Set scan mode.
     *
     * @param int $mode Scan mode.
     *
     * @return self
     */
    public function setMode(int $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * Get project name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set project name.
     *
     * @param string $name Project name
     *
     * @return self
     */
    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get project description.
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set project description.
     *
     * @param string $description Project description
     *
     * @return self
     */
    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get front matter.
     *
     * @param array<string, string> $init Initial front matter
     *
     * @return string
     */
    public function getFrontMatter(array $init = []): string
    {
        $result = '';
        foreach (\array_merge($init, $this->frontMatter) as $key => $value) {
            $result .= "$key: ".Escaper::escapeWithDoubleQuotes($value)."\n";
        }
        return $result;
    }

    /**
     * Get index front matter.
     *
     * @param array<string, string> $init Initial front matter
     *
     * @return string
     */
    private function getIndexFrontMatter(array $init = []): string
    {
        $result = '';
        foreach (\array_merge($init, $this->indexFrontMatter) as $key => $value) {
            $result .= "$key: ".Escaper::escapeWithDoubleQuotes($value)."\n";
        }
        return $result;
    }

    /**
     * Add project front matter.
     *
     * @param string $key Key
     * @param string $value Value
     *
     * @return self
     */
    public function addFrontMatter(string $key, string $value): self
    {
        $this->frontMatter[$key] = $value;

        return $this;
    }
    /**
     * Add index front matter.
     *
     * @param string $key Key
     * @param string $value Value
     *
     * @return self
     */
    public function addIndexFrontMatter(string $key, string $value): self
    {
        $this->indexFrontMatter[$key] = $value;

        return $this;
    }
}
