# PHP static type checker

This is a fast and simple static code analyzer for PHP. It depends on the `php-ast` PECL extension.

## How to install and use

I recommend to use a folder structure with the following resources for any sizable software
project:

`./Makefile`, `./helpers/`, `./src/`, `./build/`.

The Makefile should contain targets to install and run the static code analysis.

```
./helpers/php_static_type_checker.php:
	wget https://codeberg.org/Lerchensporn/php_static_type_checker/php_static_type_checker.php \
	    -o "$@"
	chmod +x "$@"

.PHONY: check
check:
    ./helpers/php_static_type_checker.php <file with main entry point>
```

This `check` target you can add as a dependency to your `build` target.

A properly written application should generally have a `main` function as the single entrypoint.
Execute it conditionally to allow the static code analyzer to load the code without executing it:

```
if (!defined('STATIC_CODE_ANALYSIS')) {
    exit(main($argv));
}
```

## Design aspects

The motivation to develop this tool was frustration with Psalm and PHPStan, which are incredibly
slow, easily run out of memory, are cumbersome to configure, and are unable to load included files
automatically. Static code analysis is supposed to increase developer efficiency, but with the
mentioned tools I spend more time to configure and run them than they save me.

Design objectives:

- The key objective is developer efficiency, specifically to waste less time on testing and fixing typos.
  Reducing the amount of defects that reach production is a side effect.

- Not using a configuration file because the tool shall be simple to use.

- Using a small amount of code to implement only the most valuable features. The type checker
  is quite poweful already and additional features need a strong justification.

- Released in a single, possibly amalgated file, because I don't like package managers.

- Fast performance.

## How could PHP better support static code analysis?

It could be more convenient if we could omit the `defined('STATIC_CODE_ANALYSIS')` check. But the
obstacle is PHP's autoloading functionality. It requires to execute the code to get informed about
the existing identifiers. I dislike the autoloading approach also because what is actually loads is
intransparent and not explicit. A possible workaround would be to execute only files passed via a
`--eval` command-line flag before checking the other files.

A static code analyzer should be able to resolve paths of included files with ease. PHP's ability
to include other files is powerful and dynamic, which makes it harder to analyze.

Static code analysis would be more powerful if we had an more descriptive array and list data
types. Other static code analyzers rely on doc comments here, another workaround we be attributes.

Case insensitive identifiers in PHP are a big bummer. It would be nice to have this removed in
future versions.

An awkward implementation detail is that PHP has different data schemas for union type hints,
type hints with a single type, and nullable types, requiring lots of case distinctions. A type hint
with a single type could be stored as a list with one entry, making it a special case of union
types. Instead, we have inconsistent interfaces with ReflectionNamedType and ReflectionUnionType.

What I don't like is that a parameter type hint `function (string $parameter=null) {...}`
is treated by PHP just like `function (?string $parameter=null) {...}`. This is surprising
behavior. This magical transformation of the type hint only works for `null` default values.
