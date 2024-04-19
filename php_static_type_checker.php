#!/usr/bin/env php
<?php declare(strict_types=1);

namespace { const STATIC_CODE_ANALYSIS = true; }

namespace StaticTypeChecker
{

class DefinedVariable
{
    # A null value for `$possible_types` means the possible types are unknown but may be determined
    # with more effort. This is different from `['mixed']`.

    function __construct(
        public string $name,
        public ?array $possible_types = null,
    ) {}
}

class ASTContext
{
    # TODO: Clarify which functions taking ASTContext as argument should be class members?

    public ?\ReflectionFunctionAbstract $function = null;
    public ?\ReflectionClass $class;
    public array $defined_variables = [];
    public bool $has_error = false;
    public bool $has_return = false;
    public string $namespace='';
    public string $file_name;
    public array $global_scope_variables;
    public array $use_aliases = [];

    function __construct()
    {
        $this->reset_defined_variables();
    }

    function reset_defined_variables()
    {
        $this->global_scope_variables = $this->defined_variables;
        $this->defined_variables = [];
        $this->add_defined_variable('_GET', 'array');
        $this->add_defined_variable('_ENV', 'array');
        $this->add_defined_variable('_POST', 'array');
        $this->add_defined_variable('_FILES', 'array');
        $this->add_defined_variable('_COOKIE', 'array');
        $this->add_defined_variable('_SERVER', 'array');
        $this->add_defined_variable('_GLOBALS', 'array');
        $this->add_defined_variable('_REQUEST', 'array');
        $this->add_defined_variable('_SESSION', 'array');
    }

    function fq_class_name(\ast\Node $name_node): string
    {
        if ($name_node->flags === \ast\flags\NAME_FQ || $name_node->children['name'] === 'self') {
            return $name_node->children['name'];
        }
        else {
            return $this->use_aliases[$name_node->children['name']] ??
                   $this->namespace . $name_node->children['name'];
        }
    }

    function reflection_type_from_ast(?\ast\Node $node, bool $has_default_null=false):
        null|\ReflectionUnionType|\ReflectionNamedType
    {
        # About `$has_default_null`: Parameter type hints with the default value `null` are implicitly
        # made nullable. This misfeature of PHP is an element of surprise and adds undesirable
        # complexity to static code analysis. I hope it will be removed in future PHP versions.

        if ($node === null) {
            return null;
        }
        if ($node->kind === \ast\AST_TYPE_UNION) {
            return new AST_ReflectionUnionType($this, $node, $has_default_null);
        }
        else if ($node->kind === \ast\AST_NULLABLE_TYPE) {
            # We get this `kind` for `?string` but neither for `null|string` nor for `null`
            return new AST_ReflectionNamedType($this, $node->children['type'], true);
        }
        return new AST_ReflectionNamedType($this, $node, $has_default_null);
    }

    function add_defined_variable(string $var, null|string|array|\ReflectionType $type)
    {
        if (is_string($type)) {
            $type_list = [$type];
        }
        else if (is_array($type)) {
            $type_list = $type;
        }
        else if ($type instanceof \ReflectionType) {
            $type_list = type_list_from_reflection_type($type);
        }
        else {
            $type_list = null;
        }
        if (!isset($this->defined_variables[$var])) {
            $this->defined_variables[$var] = new DefinedVariable($var, $type_list);
            return;
        }
        if ($this->defined_variables[$var]->possible_types === null ||
            $this->defined_variables[$var]->possible_types === ['mixed'])
        {
            return;
        }
        if ($type === null) {
            $this->defined_variables[$var]->possible_types = null;
            return;
        }
        $this->defined_variables[$var]->possible_types =
            array_unique([...$this->defined_variables[$var]->possible_types, ...$type_list]);
    }

    function error(string $message, \ast\Node $node): void
    {
        if (str_starts_with($this->file_name, getcwd())) {
            $file_name = '.' . substr($this->file_name, strlen(getcwd()));
        }
        print("`{$this->file_name}` line \e[1m{$node->lineno}\e[0m:\n$message\n");
        $this->has_error = true;
    }
}

function get_primitive_type(mixed $x): ?string
{
     return match (gettype($x)) {
        'integer' => 'int',
        'boolean' => 'bool',
        'double' => 'float',
        'string' => 'string',
        'null' => 'null',
        default => throw new \Exception("Unknown primitive type: $x")
    };
}

function validate_arg_list(ASTContext $ctx, ?\ReflectionFunctionAbstract $function, \ast\Node $node): void
{
    if ($node->kind === \ast\AST_CALLABLE_CONVERT) {
        return;
    }
    foreach ($node->children as $index => $arg) {
        if ($arg instanceof \ast\Node && $arg->kind === \ast\AST_UNPACK) {
            return;
        }
        $arg_types = null;
        $parameter = null;
        if ($arg instanceof \ast\Node && $arg->kind === \ast\AST_NAMED_ARG) {
            $var = null;
            if ($arg->children['expr'] instanceof \ast\Node &&
                $arg->children['expr']->kind === \ast\AST_VAR)
            {
                $var = $arg->children['expr']->children['name'];
            }
            if ($function !== null) {
                $arg_name = $arg->children['name'];
                foreach ($function->getParameters() as $p) {
                    if ($p->name === $arg_name) {
                        $parameter = $p;
                    }
                }
                if ($parameter === null) {
                    $ctx->error("Invalid argument name `$arg_name`", $arg);
                    continue;
                }
            }
            if ($var !== null && $parameter->isPassedByReference()) {
                $ctx->add_defined_variable($var, $parameter->getType());
            }
            if ($var !== null) {
                $arg_types = $ctx->defined_variables[$var]->possible_types ?? null;
            }
        }
        else if ($function !== null) {
            if (!$function->isVariadic() && $index >= count($function->getParameters())) {
                $ctx->error("Too many arguments for function `{$function->getName()}`", $node);
                break;
            }
            if ($function->isVariadic() && $index >= count($function->getParameters())) {
                $parameter = null;
            }
            else {
                $parameter = $function->getParameters()[$index];
            }
        }
        if ($arg instanceof \ast\Node && $arg->kind === \ast\AST_VAR) {
            if ($arg->children['name'] instanceof \ast\Node) {
                continue;
            }
            if ($function === null) {
                $ctx->add_defined_variable($arg->children['name'], null);
                continue;
            }
            if ($parameter !== null && $parameter->isPassedByReference()) {
                $ctx->add_defined_variable($arg->children['name'], $parameter->getType());
            }
            if (!array_key_exists($arg->children['name'], $ctx->defined_variables)) {
                continue;
            }
            $arg_types = $ctx->defined_variables[$arg->children['name']]->possible_types;
            if ($arg_types === ['mixed']) {
                continue;
            }
        }
        else if ($arg instanceof \ast\Node) {
            $arg_types = get_possible_types($ctx, $arg);
        }
        if ($parameter === null) {
            continue;
        }
        $parameter_types = type_list_from_reflection_type($parameter->getType());
        if ($parameter_types === null || in_array('mixed', $parameter_types)) {
            continue;
        }
        if (!($arg instanceof \ast\Node)) {
            $arg_types = [get_primitive_type($arg)];
        }
        if (!type_has_supertype($arg_types, $parameter_types)) {
            $index += 1;
            $arg_types_str = implode('|', $arg_types);
            $parameter_types_str = implode('|', $parameter_types);
            $ctx->error("In the call to `{$function->getName()}`, type `$arg_types_str` of argument $index " .
                "is not compatible with parameter type `$parameter_types_str`", $node);
        }
    }
    if ($function === null) {
        return;
    }
    $num_required_parameters = 0;
    foreach ($function->getParameters() as $parameter) {
        if (!$parameter->isOptional()) {
            $num_required_parameters += 1;
        }
    }
    if (count($node->children) < $num_required_parameters) {
        $ctx->error("Too few arguments provided to function `{$function->getName()}`", $node);
    }
}

function get_possible_methods(ASTContext $ctx, \ast\Node $node): ?array
{
    // Return value: `null` means unknown but possibly valid method,
    // `[]` means we know that the method does not exist.

    if (!is_string($node->children['method'])) {
        return null;
    }
    $possible_types = get_possible_types($ctx, $node->children['expr']);
    if ($possible_types === null) {
        return null;
    }
    $possible_methods = [];
    foreach ($possible_types as $possible_type) {
        if (!class_exists($possible_type) && !trait_exists($possible_type) &&
            !interface_exists($possible_type))
        {
            return null;
        }
        $class = new \ReflectionClass($possible_type);
        if ($class->hasMethod($node->children['method'])) {
            $possible_methods[] = $class->getMethod($node->children['method']);
        }
    }
    return $possible_methods;
}

function type_list_from_reflection_type(?\ReflectionType $type): ?array
{
    if ($type instanceof \ReflectionNamedType) {
        if ($type->allowsNull() && !in_array($type->getName(), ['null', 'mixed'])) {
            return [$type->getName(), 'null'];
        }
        else {
            return [$type->getName()];
        }
    }
    else if ($type instanceof \ReflectionUnionType) {
        return array_map(fn ($x) => $x->getName(), $type->getTypes());
    }
    return null;
}

function type_has_supertype(?array $types, ?array $supertypes): bool
{
    if ($types === null || $supertypes === null) {
        return true;
    }
    if ($types === ['mixed'] || $supertypes === ['mixed']) {
        return true;
    }
    foreach ($types as $type) {
        foreach ($supertypes as $supertype) {
            if ($supertype === 'string' && class_exists($type) &&
                in_array(\Stringable::class, class_implements($type)) ||
                $supertype === 'object' && class_exists($type) ||
                $type === 'object' && class_exists($supertype) ||
                $type === 'string' && $supertype === 'callable' ||
                $type === $supertype || is_subclass_of($type, $supertype))
            {
                return true;
            }
        }
    }
    return false;
}

class AST_ReflectionUnionType extends \ReflectionUnionType
{
    function __construct(private ASTContext $ctx, private \ast\Node $node, private bool $has_default_null)
    {}

    function getTypes(): array
    {
        $types = [];
        foreach ($this->node->children as $child) {
            $types []= new AST_ReflectionNamedType($this->ctx, $child, false);
        }
        if ($this->has_default_null) {
            $types[] = new AST_ReflectionNamedType($this->ctx, null, false);
        }
        return $types;
    }
}

class AST_ReflectionNamedType extends \ReflectionNamedType
{
    function __construct(private ASTContext $ctx, private ?\ast\Node $node, private bool $is_nullable)
    {}

    function isBuiltin(): bool
    {
        return $this->node === null || $this->node->kind === \ast\AST_TYPE;
    }

    function allowsNull(): bool
    {
        return
            $this->node === null || $this->is_nullable ||
            $this->node->kind === \ast\AST_TYPE && $this->node->flags === \ast\flags\TYPE_NULL ||
            $this->node->kind === \ast\AST_TYPE && $this->node->flags === \ast\flags\TYPE_MIXED;
    }

    function getName(): string
    {
        if ($this->node === null) {
            return 'null';
        }
        if ($this->node->kind === \ast\AST_TYPE) {
            return match ($this->node->flags) {
                \ast\flags\TYPE_ARRAY => 'array',
                \ast\flags\TYPE_CALLABLE => 'callable',
                \ast\flags\TYPE_VOID => 'void',
                \ast\flags\TYPE_BOOL => 'bool',
                \ast\flags\TYPE_LONG => 'int',
                \ast\flags\TYPE_DOUBLE => 'float',
                \ast\flags\TYPE_STRING => 'string',
                \ast\flags\TYPE_ITERABLE => 'iterable',
                \ast\flags\TYPE_OBJECT => 'object',
                \ast\flags\TYPE_NULL => 'null',
                \ast\flags\TYPE_FALSE => 'false',
                \ast\flags\TYPE_TRUE => 'true',
                \ast\flags\TYPE_STATIC => 'static',
                \ast\flags\TYPE_MIXED => 'mixed',
                \ast\flags\TYPE_NEVER => 'never',
                default => throw new \Exception('Unknown type flag')
            };
        }
        else if ($this->node->kind === \ast\AST_NAME) {
            return $this->ctx->fq_class_name($this->node);
        }
    }
}

class ReflectionClosure extends \ReflectionFunctionAbstract
{
    private bool $has_yield;

    function __construct(private ASTContext $ctx, private \ast\Node $node)
    {
        $this->has_yield = $this->has_yield($node);
    }

    private function has_yield(\ast\Node $node): bool
    {
        foreach ($node->children as $child) {
            if (!($child instanceof \ast\Node)) {
                continue;
            }
            if ($child->kind === \ast\AST_YIELD || $this->has_yield($child)) {
                return true;
            }
        }
        return false;
    }

    function getFileName(): string
    {
        return $this->ctx->file_name;
    }

    function getDeclaringClass(): ?\ReflectionClass
    {
        return $this->ctx->class;
    }

    function __toString()
    {
        return '';
    }

    function isGenerator(): bool
    {
        return $this->has_yield;
    }

    function getReturnType(): ?\ReflectionType
    {
        if ($this->node->children['returnType'] === null) {
            return null;
        }
        return $this->ctx->reflection_type_from_ast($this->node->children['returnType']);
    }
}

function get_possible_types(ASTContext $ctx, mixed $node, bool $print_error=false): ?array
{
    # Return value `null` means we don't know the type, `[]` means the type is invalid.

    if ($node === null) {
        return ['void'];
    }
    if (!($node instanceof \ast\Node)) {
        return [get_primitive_type($node)];
    }
    if ($node->kind === \ast\AST_CONST) {
        $const = strtolower($node->children['name']->children['name']);
        if ($const === 'null') {
            return ['null'];
        }
        if ($const === 'false' || $const === 'true') {
            return ['bool'];
        }
    }
    if ($node->kind === \ast\AST_METHOD_CALL) {
        $possible_methods = get_possible_methods($ctx, $node);
        if ($possible_methods === null) {
            return null;
        }
        $return_types = [];
        foreach ($possible_methods as $possible_method) {
            $return_type = type_list_from_reflection_type($possible_method->getReturnType());
            if ($return_type === null) {
                return null;
            }
            $return_types = [...$return_types, ...$return_type];
        }
        return $return_types;
    }
    if ($node->kind === \ast\AST_CALL) {
        if ($node->children['args']->kind === \ast\AST_CALLABLE_CONVERT) {
            return ['callable'];
        }
        if ($node->children['expr']->kind === \ast\AST_NAME) {
            $function = $node->children['expr']->children['name'];
            if (!function_exists($function)) {
                return null;
            }
            return type_list_from_reflection_type((new \ReflectionFunction($function))->getReturnType());
        }
    }
    if ($node->kind === \ast\AST_NEW) {
        return [$ctx->fq_class_name($node->children['class'])];
    }
    if ($node->kind === \ast\AST_VAR) {
        $var = $node->children['name'];
        if (!array_key_exists($node->children['name'], $ctx->defined_variables)) {
            return [];
        }
        return $ctx->defined_variables[$node->children['name']]->possible_types;
    }
    if ($node->kind === \ast\AST_PROP) {
        if (!is_string($node->children['prop'])) {
            return null;
        }
        $possible_expr_types = get_possible_types($ctx, $node->children['expr']);
        if ($possible_expr_types === null) {
            return null;
        }
        $possible_types = [];
        $builtin_types = ['int', 'float', 'string', 'null', 'bool'];
        foreach ($possible_expr_types as $possible_expr_type) {
            if (in_array(strtolower($possible_expr_type), ['stdclass', 'object', 'mixed'])) {
                return null;
            }
            if (in_array($possible_expr_type, $builtin_types)) {
                continue;
            }
            if (!class_exists($possible_expr_type) && !interface_exists($possible_expr_type)) {
                # Returning null to avoid error messages about properties when we already
                # have one about the incorrect class name.
                return null;
            }
            $class = (new \ReflectionClass($possible_expr_type));
            if (!$class->hasProperty($node->children['prop'])) {
                continue;
            }
            $type_list = type_list_from_reflection_type(
                $class->getProperty($node->children['prop'])->getType()
            );
            if ($type_list === null) {
                return null;
            }
            $possible_types = [...$possible_types, ...$type_list];
        }
        if (count($possible_types) === 0) {
            $possible_types_str = implode('|', $possible_expr_types);
            if ($print_error) {
                $ctx->error("Variable of type `$possible_types_str` does not have property " .
                    "`{$node->children['prop']}`", $node);
            }
            return null;
        }
        return $possible_types;
    }
    return null;
}

function validate_ast_node(ASTContext $ctx, \ast\Node $node, array $parents=[]): ?ASTContext
{
    $type_node = null;
    if (array_key_exists('returnType', $node->children)) {
        $type_node = $node->children['returnType'];
    }
    else if (array_key_exists('type', $node->children)) {
        $type_node = $node->children['type'];
    }
    if ($type_node !== null) {
        if ($type_node->kind === \ast\AST_NULLABLE_TYPE) {
            $type_node = $type_node->children['type'];
        }
        if ($type_node->kind === \ast\AST_TYPE_UNION) {
            $type_nodes = [...$type_node->children];
        }
        else {
            $type_nodes = [$type_node];
        }
        foreach ($type_nodes as $type_node) {
            if ($type_node->kind === \ast\AST_TYPE) {
                continue;
            }
            $type_hint = $ctx->fq_class_name($type_node);
            if (!class_exists($type_hint) && !interface_exists($type_hint)) {
                $ctx->error("Undefined type `$type_hint` in type hint", $node);
            }
        }
    }

    if ($node->kind === \ast\AST_CLOSURE) {
        $ctx2 = clone $ctx;
        $ctx2->function = new ReflectionClosure($ctx, $node);
        $ctx2->defined_variables = [];
        if (array_key_exists('this', $ctx->defined_variables)) {
            $ctx2->defined_variables['this'] = $ctx->defined_variables['this'];
        }
        foreach ($node->children['uses']->children ?? [] as $use) {
            $var = $use->children['name'];
            if (array_key_exists($var, $ctx->defined_variables)) {
                $ctx2->add_defined_variable($var, $ctx->defined_variables[$var]->possible_types);
                continue;
            }
            $ctx2->add_defined_variable($var, null);
            if ($use->flags !== \ast\flags\CLOSURE_USE_REF) {
                $ctx->error("Undefined closure variable `$var`", $node);
            }
        }
        return $ctx2;
    }
    else if ($node->kind === \ast\AST_ARROW_FUNC) {
        $ctx = clone $ctx;
        $ctx->function = new ReflectionClosure($ctx, $node);
        return $ctx;
    }
    else if ($node->kind === \ast\AST_PARAM) {
        $has_default_null =
            $node->children['default'] instanceof \ast\Node &&
            $node->children['default']->kind === \ast\AST_CONST &&
            $node->children['default']->children['name']->children['name'] === 'null';
        $type = $ctx->reflection_type_from_ast($node->children['type'], $has_default_null);
        $ctx->add_defined_variable($node->children['name'], $type);
    }
    else if ($node->kind === \ast\AST_CLASS_NAME) {
        $class_name = $ctx->fq_class_name($node->children['class']);
        if (!class_exists($class_name) && !interface_exists($class_name)) {
            $ctx->error("Undefined class `$class_name`", $node);
        }
    }
    else if ($node->kind === \ast\AST_CLASS_CONST) {
        if ($node->children['class']->kind !== \ast\AST_NAME || !is_string($node->children['const'])) {
            return $ctx;
        }
        $class_name = $ctx->fq_class_name($node->children['class']);
        if ($class_name === 'self') {
            $class = $ctx->function->getDeclaringClass();
            if ($class === null) {
                $ctx->error("Cannot access `self` when no class scope is active", $node);
                return $ctx;
            }
        }
        else {
            if (!class_exists($class_name)) {  # PHP regards enums as classes, too
                $ctx->error("Undefined class `$class_name`", $node);
                return $ctx;
            }
            $class = new \ReflectionClass($class_name);
        }
        if (!$class->hasConstant($node->children['const'])) {
            $ctx->error("Undefined class constant `$class_name::{$node->children['const']}`", $node);
        }
    }
    else if ($node->kind === \ast\AST_RETURN) {
        if ($ctx->function === null) {
            return $ctx;  # PHP supports top-level return
        }
        if ($ctx->function->isGenerator()) {
            return $ctx;
        }
        $ctx->has_return = true;
        $returned_type = get_possible_types($ctx, $node->children['expr']);
        $return_type_hint = type_list_from_reflection_type($ctx->function->getReturnType());
        if (!type_has_supertype($returned_type, $return_type_hint)) {
            $returned_type_str = implode('|', $returned_type);
            $return_type_hint_str = implode('|', $return_type_hint);
            $ctx->error("Returned type `$returned_type_str` is incompatible with the return type hint " .
                "`$return_type_hint_str`", $node);
        }
    }
    else if ($node->kind === \ast\AST_ASSIGN || $node->kind === \ast\AST_ASSIGN_OP) {
        if ($node->children['var']->kind === \ast\AST_VAR &&
            is_string($node->children['var']->children['name']))
        {
            $type = get_possible_types($ctx, $node->children['expr']);
            $ctx->add_defined_variable($node->children['var']->children['name'], $type);
            return $ctx;
        }
        $possible_types = get_possible_types($ctx, $node->children['var']);
        $expr_types = get_possible_types($ctx, $node->children['expr']);
        if (!type_has_supertype($expr_types, $possible_types)) {
            $possible_types_str = implode('|', $possible_types);
            $expr_types_str = implode('|', $expr_types);
            $ctx->error("Cannot assign type `$expr_types_str` to property of type " .
                "`$possible_types_str`", $node);
        }
    }
    else if ($node->kind === \ast\AST_CATCH) {
        if ($node->children['var'] !== null) {
            $var = $node->children['var']->children['name'];
            $possible_types = [];
            foreach ($node->children['class']->children as $class_node) {
                $class = $ctx->fq_class_name($class_node);
                if (!class_exists($class) && !interface_exists($class)) {
                    $ctx->error("Undefined class `$class`", $node->children['class']);
                    $possible_types = null;
                }
                if ($possible_types !== null) {
                    $possible_types[] = $class;
                }
            }
            $ctx->defined_variables[$var] = new DefinedVariable($var, $possible_types);
        }
    }
    else if ($node->kind === \ast\AST_STATIC_CALL) {
        if ($node->children['class']->kind !== \ast\AST_NAME ||
            !is_string($node->children['method']))
        {
            return $ctx;
        }
        $class_name = $ctx->fq_class_name($node->children['class']);
        $method = $node->children['method'];
        if ($class_name === 'self') {
            $class = $ctx->function->getDeclaringClass();
            if ($class === null) {
                $ctx->error("Cannot access `self` when no class scope is active", $node);
                return $ctx;
            }
        }
        else if (!class_exists($class_name)) {
            $ctx->error("Undefined class `$class_name`", $node);
            return $ctx;
        }
        else  {
            $class = new \ReflectionClass($class_name);
        }
        if (!$class->hasMethod($method)) {
            $ctx->error("Undefined method `$class_name::$method`", $node);
            return $ctx;
        }
        $method = $class->getMethod($method);
        if (!$method->isStatic()) {
            $ctx->error("Method `$class_name::{$method->getName()}` is not static", $node);
        }
        validate_arg_list($ctx, $method, $node->children['args']);
    }
    else if ($node->kind === \ast\AST_METHOD_CALL) {
        $possible_methods = get_possible_methods($ctx, $node);
        if ($possible_methods === []) {
            $ctx->error("Undefined method `{$node->children['method']}`", $node);
        }
        $method = count($possible_methods ?? []) === 1 ? $possible_methods[0] : null;
        validate_arg_list($ctx, $method, $node->children['args']);
    }
    else if ($node->kind === \ast\AST_NEW) {
        if ($node->children['class']->kind !== \ast\AST_NAME) {
            return $ctx;
        }
        $class_name = $ctx->fq_class_name($node->children['class']);
        if (!class_exists($class_name)) {
            $ctx->error("Undefined class `$class_name`", $node);
            return $ctx;
        }
        $constructor = (new \ReflectionClass($class_name))->getConstructor();
        if ($constructor !== null) {
            validate_arg_list($ctx, $constructor, $node->children['args']);
        }
        else if (count($node->children['args']->children) > 0) {
            $ctx->error("The constructor of class `$class_name` does not accept arguments", $node);
        }
    }
    else if ($node->kind === \ast\AST_GLOBAL) {
        if (!is_string($node->children['var']->children['name'])) {
            return $ctx;
        }
        $var = $node->children['var']->children['name'];
        if (!isset($ctx->global_scope_variables[$var])) {
            $ctx->error("Undefined global variable `$$var`", $node);
        }
        $ctx->add_defined_variable($var, $ctx->global_scope_variables[$var]->possible_types ?? null);
    }
    else if ($node->kind === \ast\AST_CONST) {

        # There is a fallback to the global namespace for functions and constants, but not for
        # classes.

        $c = $node->children['name']->children['name'];
        if (!defined($c) &&
            ($node->children['name']->flags === \ast\flags\NAME_FQ || !defined($ctx->namespace . $c)))
        {
            $ctx->error("Undefined constant `$c`", $node);
        }
    }
    else if ($node->kind === \ast\AST_CALL) {
        $function = null;
        if ($node->children['expr']->kind === \ast\AST_NAME) {
            $function_name = $node->children['expr']->children['name'];
            if ($node->children['expr']->flags !== \ast\flags\NAME_FQ &&
                function_exists($ctx->namespace . $function_name))
            {
                $function_name = $ctx->namespace . $function_name;
            }
            if (function_exists($function_name)) {
                $function = new \ReflectionFunction($function_name);
            }
            else if (!function_exists($function_name)) {
                $ctx->error("Undefined function `$function_name`", $node);
            }
        }
        validate_arg_list($ctx, $function, $node->children['args']);
    }
    else if ($node->kind === \ast\AST_STATIC) {
        $var = $node->children['var']->children['name'];
        $type = get_possible_types($ctx, $node->children['default']);
        $ctx->defined_variables[$var] = new DefinedVariable($var, $type);
    }
    else if ($node->kind === \ast\AST_VAR) {
        $var = $node->children['name'];
        if ($var instanceof \ast\Node) {
            return $ctx;
        }
        if ($parents[count($parents) - 1]->kind === \ast\AST_REF &&
            $parents[count($parents) - 2]->kind === \ast\AST_FOREACH)
        {
            $ctx->defined_variables[$var] = new DefinedVariable($var);
            return $ctx;
        }
        for ($i = count($parents) - 1; $i >= 0; --$i) {
            if ($parents[$i]->kind !== \ast\AST_ARRAY_ELEM) {
                break;
            }
            $i -= 1;
            if ($parents[$i]->kind !== \ast\AST_ARRAY) {
                break;
            }
        }
        if ($parents[$i]->kind === \ast\AST_FOREACH && !isset($parents[$i + 1])) {
            $ctx->defined_variables[$var] = new DefinedVariable($var);
            return $ctx;
        }
        if ($parents[$i]->kind === \ast\AST_FOREACH &&
            $parents[$i + 1] === $parents[$i]->children['value'])
        {
            $ctx->defined_variables[$var] = new DefinedVariable($var);
            return $ctx;
        }
        if ($parents[count($parents) - 1]->kind === \ast\AST_ARRAY_ELEM &&
            $parents[$i]->kind === \ast\AST_ASSIGN &&
            $parents[$i + 1] === $parents[$i]->children['var'])
        {
            $ctx->defined_variables[$var] = new DefinedVariable($var);
            return $ctx;
        }
        if (!isset($ctx->defined_variables[$var])) {
            $ctx->error("Undefined variable `$$var`", $node);
            return $ctx;
        }
    }
    else if ($node->kind === \ast\AST_NAMESPACE) {
        if ($node->children['stmts'] !== null) {
            $ctx = clone $ctx;
        }
        $ctx->namespace = $node->children['name'] . '\\';
        return $ctx;
    }
    else if ($node->kind === \ast\AST_USE_ELEM) {
        if ($node->children['alias'] === null) {
            $split = explode('\\', $node->children['name']);
            $alias = $split[count($split) - 1];
        }
        else {
            $alias = $node->children['alias'];
        }
        $ctx->use_aliases[$alias] = $node->children['name'];
    }
    else if ($node->kind === \ast\AST_CLASS) {
        $ctx = clone $ctx;
        $ctx->class = new \ReflectionClass($ctx->namespace . $node->children['name']);
        return $ctx;
    }
    else if ($node->kind === \ast\AST_PROP) {
        get_possible_types($ctx, $node, print_error: true);
    }
    else if ($node->kind === \ast\AST_FUNC_DECL || $node->kind === \ast\AST_METHOD) {
        $ctx = clone $ctx;
        $ctx->reset_defined_variables();
        if ($node->kind === \ast\AST_METHOD) {
            $ctx->function = $ctx->class->getMethod($node->children['name']);
            if (!$ctx->function->isStatic()) {
                $ctx->defined_variables['this'] = new DefinedVariable('this', [$ctx->class->getName()]);
            }
        }
        else {
            $function_name = str_starts_with($node->children['name'], '\\') ?
                $node->children['name'] : $ctx->namespace . $node->children['name'];
            $ctx->function = new \ReflectionFunction($function_name);
        }
        foreach ($node->children as $child) {
            if ($child instanceof \ast\Node) {
                validate_ast_children($ctx, $child);
            }
        }
        $f = $ctx->function;
        if (!$f->isGenerator() && !$ctx->has_return && $f->getReturnType() !== null &&
            (!$f->getReturnType() instanceof \ReflectionNamedType ||
            !in_array($f->getReturnType()->getName(), ['void', 'never'])))
        {
            $ctx->error(
                "Function `{$f->getName()}` has a non-void return type hint but lacks a " .
                "return statement", $node
            );
        }
        return null;
    }
    return $ctx;
}

function validate_ast_children(ASTContext $ctx, \ast\Node $node, array $parents=[]): void
{
    $parents []= $node;
    foreach ($node->children as $key => $child) {
        if ($child instanceof \ast\Node) {
            $child_ctx = validate_ast_node($ctx, $child, $parents);
            if ($child_ctx !== null) {
                validate_ast_children($child_ctx, $child, $parents);
                $ctx->has_error = $ctx->has_error || $child_ctx->has_error;
            }
        }
    }
}

function main(array $argv): int
{
    if (count($argv) === 1) {
        print(<<<EOT
            Specify PHP files to check.
            Options:
            --ignore-file-prefix <prefix>
            \tIgnore files or directories with the specified prefix. Example: `vendor/`.
            --statistics
            \tPrint SLOC count and lists of the checked and of the ignored files

            EOT
        );
        return 0;
    }
    $ignored_file_prefixes = [];
    $print_statistics = false;
    for ($i = 0; $i < count($argv); ++$i) {
        if ($argv[$i] === '--ignore-file-prefix' && $i < count($argv) - 1) {
            $prefix = realpath('') . '/' . $argv[++$i];
            $prefix = preg_replace('|/(\./)+|', '/', $prefix);
            do {
                $prefix = preg_replace('|/[^/]*?/\.\.|', '', $prefix, -1, $count);
            } while ($count > 0);
            $ignored_file_prefixes[] = $prefix;
            continue;
        }
        if ($argv[$i] === '--statistics') {
            $print_statistics = true;
            continue;
        }
        if (!is_file($argv[$i])) {
            print("The file `{$argv[$i]}` does not exist\n");
            return 1;
        }
        require_once($argv[$i]);
    }

    $sloc_count = 0;
    $make_self_check = in_array(__FILE__, array_map(realpath(...), array_slice($argv, 1)));
    $has_error = false;
    $checked_files = [];
    $ignored_files = [];
    foreach (get_included_files() as $file_name) {
        if (!$make_self_check && $file_name === __FILE__) {
            continue;
        }
        $ignore = false;
        foreach ($ignored_file_prefixes as $ignored_file_prefix) {
            if (str_starts_with($file_name, $ignored_file_prefix)) {
                $ignored_files[] = $file_name;
                $ignore = true;
                break;
            }
        }
        if ($ignore) {
            continue;
        }
        $checked_files[] = $file_name;
        $code = file_get_contents($file_name);
        if ($print_statistics) {
            $sloc_count += substr_count($code, "\n");
        }

        $node = \ast\parse_code($code, 100);
        $ctx = new ASTContext;
        $ctx->file_name = $file_name;
        $ctx->add_defined_variable('argc', 'int');
        $ctx->add_defined_variable('argv', 'array');
        validate_ast_children($ctx, $node);
        $has_error |= $ctx->has_error;
    }
    if ($print_statistics) {
        $checked_files = count($checked_files) === 0 ? ' (none)' : "\n" . implode("\n", $checked_files);
        $ignored_files = count($ignored_files) === 0 ? ' (none)' : "\n" . implode("\n", $ignored_files);
        print("$sloc_count lines of code have been checked.\n");
        print("Checked files:$checked_files\n");
        print("Ignored files:$ignored_files\n");
    }

    return $has_error ? 1 : 0;
}

exit(main($argv));

}

