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

/**
 * PHP documentation builder.
 */
final class PhpDocBuilder
{
    /**
     * PHPDoc instance.
     */
    private PhpDoc $doc;
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
     */
    private function __construct(string $namespace)
    {
        $this->doc = PhpDoc::fromNamespace($namespace);
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
        $this->doc->setAuthors($authors);

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
        $this->doc->setMode($mode);

        return $this;
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
        $this->doc->setFilter($ignore);

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
        $this->doc->setOutput($output);

        return $this;
    }
    /**
     * Set project name.
     *
     * @param string $name Name
     *
     * @return self
     */
    public function setName(string $name): self
    {
        $this->doc->setName($name);

        return $this;
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
        $this->doc->setDescription($description);

        return $this;
    }

    /**
     * Set project image.
     *
     * @param string $image Project image
     *
     * @return self
     */
    public function setImage(string $image): self
    {
        $this->doc->addFrontMatter('image', $image);
        $this->doc->addIndexFrontMatter('image', $image);

        return $this;
    }

    /**
     * Add Jekyll front matter.
     *
     * @param string $key Key
     * @param string $value Value
     * @return self
     */
    public function addFrontMatter(string $key, string $value): self
    {
        $this->doc->addFrontMatter($key, $value);

        return $this;
    }
    /**
     * Add Jekyll index front matter.
     *
     * @param string $key Key
     * @param string $value Value
     * @return self
     */
    public function addIndexFrontMatter(string $key, string $value): self
    {
        $this->doc->addIndexFrontMatter($key, $value);

        return $this;
    }
    /**
     * Run documentation builder.
     *
     * @return self
     */
    public function run(): self
    {
        $this->doc->run();

        return $this;
    }
}
