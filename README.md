# PHP static type checker

This is a fast and simple static code analyzer for PHP. It depends on the `php-ast` PECL extension.
It resolves included files automatically and uses reflection to support any PECL extensions without
requiring stubs.

## How to install and use

```
wget https://codeberg.org/Lerchensporn/php_static_type_checker/raw/branch/master/php_static_type_checker.php
chmod +x php_static_type_checker.php
./php_static_type_checker.php
```

## Design objectives

- Released in a single PHP file with no dependencies besides `php-ast`.

- The key objective is developer efficiency, particularly to waste less time on fixing typos.
  Reducing the amount of defects that reach production is a side effect. Performance and ease of
  use are more important than finding as many errors as possible.

- Not using a configuration file because the tool shall be simple to use.

- False positives are not desirable.

- This application omits non-essential features in favor of performance and maintainability.
  I regard the tool as “almost” feature-complete with the current feature set that fits in 2500 SLOC.
  Out of scope are:
   - extensive support for PHPDoc comments,
   - support for outdated PHP versions,
   - suggestions where to add more type hints,
   - detection of dead code and unused identifiers are of scope, since it creates no runtime
     defects and may obstruct debugging,
   - sophisticated analysis of control structures.

## How could PHP better support static code analysis?

Originally I had started with the approach to include the files to analyze, which means one had
to wrap the main entry point of the application into a `if (defined('STATIC_CODE_ANALYSIS')) {…}` check.
The tool can now read all information from the AST, which took considerable effort and is a
duplication of what PHP does internally. One can think of possible approaches to improve this.

One nasty aspect is PHP's autoloading functionality. It still requires to execute the
`vendor/autoload.php` file using the `--eval` flag to get informed about the existing identifiers.
I dislike the autoloading approach also because what is actually loaded is intransparent and not
explicit.

Static code analysis would be more powerful if we had an more descriptive array and list data
types. Other static code analyzers rely on doc comments here, another solution would be attributes.

Intersection types have only rare and not important use cases but introduce a lot of complexity. I
think it was a mistake to introduce this feature.

Case insensitive identifiers in PHP are a big bummer. It would be nice to have this removed in
future versions.

An awkward implementation detail is that PHP has different data schemas for union type hints,
type hints with a single type, and nullable types, requiring lots of case distinctions. A type hint
with a single type could be stored as a list with one entry, making it a special case of union
types. Instead, we have inconsistent interfaces with ReflectionNamedType and ReflectionUnionType.

What I don't like is that a parameter type hint `function (string $parameter=null) {...}` is
treated by PHP just like `function (?string $parameter=null) {...}`. This is surprising behavior.
This magical transformation of the type hint only works for `null` default values and only for
function arguments, not for properties.

The `insteadof` operator is awkward. Something like `ignore trait2::method` would be clearer, more
powerful, and stricter (requiring `trait2::method` to exist) than `trait1::method insteadof trait2` 
