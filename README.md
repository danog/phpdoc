# danog/phpdoc

Simple markdown PHPDOC documentation generator with psalm type annotation support.

Supports, classes, abstract classes, interfaces, traits and functions thanks to [danog/class-finder](https://github.com/danog/class-finder).  

## Install

```
composer require danog/phpdoc --dev
```

## Run

```
vendor/bin/phpdoc outputDirectory [ namespace ]
```

If not provided, `namespace` will default to the namespace of the current package.  

## [API documentation](docs)
