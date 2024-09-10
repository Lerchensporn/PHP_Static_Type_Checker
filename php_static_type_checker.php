#!/usr/bin/env php
<?php declare(strict_types=1);

namespace StaticTypeChecker;

const NON_CLASS_TYPES = ['int', 'float', 'string', 'null', 'bool', 'false', 'true', 'array', 'resource'];

class DefinedVariable
{
    function __construct(public string $name, public array $possible_types)
    {}
}

class ASTContext
{
    public null|\ReflectionFunctionAbstract|AST_ReflectionFunctionAbstract $function = null;
    public array $included_files = [];
    public bool $make_self_check = false;
    public ?\ReflectionClass $class = null;
    public array $defined_classes = [];
    public array $defined_interface_names = [];
    public array $defined_functions = [];
    public array $defined_variables = [];
    public array $defined_constants = [];
    public bool $has_error = false;
    public bool $has_return = false;
    public string $namespace='';
    public string $file_name;
    public array $global_scope_variables;
    public array $use_aliases = [];
    public bool $is_in_assignment = false;

    function __construct()
    {
        $this->reset_defined_variables();
    }

    function add_defined_variable(string $var, array $type_list)
    {
        if (!array_key_exists($var, $this->defined_variables) || $type_list === [null]) {
            $this->defined_variables[$var] = new DefinedVariable($var, $type_list);
            return;
        }
        if ($this->defined_variables[$var]->possible_types === [null]) {
            return;
        }
        $this->defined_variables[$var]->possible_types =
            array_unique([...$this->defined_variables[$var]->possible_types, ...$type_list]);
    }

    function reset_defined_variables()
    {
        $this->global_scope_variables = $this->defined_variables;
        $this->defined_variables = [];
        $this->add_defined_variable('_GET', ['array']);
        $this->add_defined_variable('_ENV', ['array']);
        $this->add_defined_variable('_POST', ['array']);
        $this->add_defined_variable('_FILES', ['array']);
        $this->add_defined_variable('_COOKIE', ['array']);
        $this->add_defined_variable('_SERVER', ['array']);
        $this->add_defined_variable('_GLOBALS', ['array']);
        $this->add_defined_variable('_REQUEST', ['array']);
        $this->add_defined_variable('_SESSION', ['array']);
    }

    private static function normalize_constant_name(string $name): string
    {
        if (($last_backslash = strrpos($name, '\\')) === false) {
            return $name;
        }
        return strtolower(substr($name, 0, $last_backslash)) . substr($name, $last_backslash);
    }

    function constant_exists_with_fallback(\ast\Node $name_node): bool
    {
        $name = $this->normalize_constant_name($this->fq_name($name_node));
        if (array_key_exists($name, $this->defined_constants) || defined($name)) {
            return true;
        }

        // Look up the global scope as a fallback

        $name = $this->normalize_constant_name($name_node->children['name']);
        return array_key_exists($name, $this->defined_constants) || defined($name);
    }

    function get_constant_type(\ast\Node $name_node): ?string
    {
        # A return value of `null` can mean an unknown type or that the constant does not exist.

        $name = $this->normalize_constant_name($this->fq_name($name_node));
        if (array_key_exists(strtolower($name), $this->defined_constants)) {
            return get_primitive_type($this->defined_constants[strtolower($name)]);
        }
        if (defined($name)) {
            return get_primitive_type(constant($name));
        }
        if ($name_node->flags === \ast\flags\NAME_FQ) {
            return null;
        }

        // Look up the global scope as a fallback

        $name = $this->normalize_constant_name($name_node->children['name']);
        if (array_key_exists(strtolower($name), $this->defined_constants)) {
            return get_primitive_type($this->defined_constants[strtolower($name)]);
        }
        if (defined($name)) {
            return get_primitive_type(constant($name));
        }
        return null;
    }

    function get_class(string $name): ?\ReflectionClass
    {
        # Beware that PHP's autoloading might not get triggered correctly if we passed lowercased
        # identifiers, even if they would normally be case-insensitive in PHP.

        $lower_name = strtolower($name);
        if (array_key_exists($lower_name, $this->defined_classes)) {
            return $this->defined_classes[$lower_name];
        }
        if (class_exists($name) || trait_exists($name) || interface_exists($name)) {
            return new \ReflectionClass($name);
        }
        return null;
    }

    function get_function(\ast\Node $name_node): ?\ReflectionFunctionAbstract
    {
        $name = $this->fq_name($name_node);
        if (array_key_exists(strtolower($name), $this->defined_functions)) {
            return $this->defined_functions[strtolower($name)];
        }
        if (function_exists($name)) {
            return new \ReflectionFunction($name);
        }
        if ($name_node->flags === \ast\flags\NAME_FQ) {
            return null;
        }

        // Look up the global scope as a fallback

        $name = $name_node->children['name'];
        if (array_key_exists(strtolower($name), $this->defined_functions)) {
            return $this->defined_functions[strtolower($name)];
        }
        if (function_exists($name)) {
            return new \ReflectionFunction($name);
        }
        return null;
    }

    function class_exists(string $name)
    {
        return array_key_exists(strtolower($name), $this->defined_classes) || class_exists($name);
    }

    function interface_exists(string $name)
    {
        return in_array(strtolower($name), $this->defined_interface_names) || interface_exists($name);
    }

    function function_exists(string $name)
    {
        return array_key_exists(strtolower($name), $this->defined_functions) || function_exists($name);
    }

    function fq_name(\ast\Node $name_node): string
    {
        if ($name_node->flags === \ast\flags\NAME_FQ) {
            return $name_node->children['name'];
        }
        $split = explode('\\', $name_node->children['name'], 2);
        if (array_key_exists(strtolower($split[0]), $this->use_aliases)) {
            $split[0] = $this->use_aliases[strtolower($split[0])];
        }
        else {
            $split[0] = $this->namespace . $split[0];
        }
        return implode('\\', $split);
    }

    function fq_class_name(\ast\Node $name_node, bool $print_error=true): ?string
    {
        if ($name_node->children['name'] === 'self') {
            if ($this->class === null) {
                if ($print_error) {
                    $this->error('Cannot access `self` when no class scope is active', $name_node);
                }
                return null;
            }
            return $this->class->getName();
        }
        if ($name_node->children['name'] === 'static') {
            if ($this->class === null) {
                if ($print_error) {
                    $this->error('Cannot access `static` when no class scope is active', $name_node);
                }
                return null;
            }
            return $this->class->getName();
        }
        if ($name_node->children['name'] === 'parent') {
            if ($this->class === null) {
                if ($print_error) {
                    $this->error('Cannot access `parent` when no class scope is active', $name_node);
                }
                return null;
            }
            $parent = $this->class->getParentClass();
            if ($parent === false) {
                if ($print_error) {
                    $this->error('Cannot access `parent` when the current class has no parent',
                        $name_node);
                }
                return null;
            }
            return $parent->getName();
        }
        return $this->fq_name($name_node);
    }

    function reflection_type_from_ast(?\ast\Node $node, bool $has_default_null=false):
        null|\ReflectionType
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
        else if ($node->kind === \ast\AST_TYPE_INTERSECTION) {
            return new AST_ReflectionIntersectionType($this, $node);
        }
        else if ($node->kind === \ast\AST_NULLABLE_TYPE) {
            # We get this `kind` for `?string` but neither for `null|string` nor for `null`
            if ($node->kind === \ast\AST_NULLABLE_TYPE) {
                if ($node->children['type']->flags === \ast\flags\TYPE_MIXED) {
                    $this->error("Type hint `mixed` must not be nullable", $node);
                }
                else if ($node->children['type']->flags === \ast\flags\TYPE_VOID) {
                    $this->error("Type hint `void` must not be nullable", $node);
                }
            }
            return AST_ReflectionNamedType::try_create($this, $node->children['type'], true);
        }
        return AST_ReflectionNamedType::try_create($this, $node, $has_default_null);
    }

    function fill_use_aliases(\ast\Node $node)
    {
        foreach ($node->children as $use_elem) {
            if ($use_elem->children['alias'] === null) {
                $split = explode('\\', $use_elem->children['name']);
                $alias = $split[count($split) - 1];
            }
            else {
                $alias = $use_elem->children['alias'];
            }
            $this->use_aliases[strtolower($alias)] = $use_elem->children['name'];
        }
    }

    function get_relative_file_name()
    {
        if (str_starts_with($this->file_name, getcwd())) {
            return '.' . substr($this->file_name, strlen(getcwd()));
        }
        return $this->file_name;
    }

    function error(string $message, \ast\Node $node): void
    {
        print("`{$this->get_relative_file_name()}` line \e[1m{$node->lineno}\e[0m:\n$message\n");
        $this->has_error = true;
    }
}

function type_to_string(array $types, bool $sort=false): string
{
    # We do sort to compare two type hints, and don't sort to output precise error messages.

    $type_list = [];
    foreach ($types as $type) {
        if (is_string($type)) {
            $type_list []= $type;
        }
        else if ($type instanceof \ReflectionNamedType) {
            $type_list []= $type->getName();
            if ($type->allowsNull() && !in_array($type->getName(), ['null', 'mixed'])) {
                $type_list []= 'null';
            }
        }
        else if ($type instanceof \ReflectionUnionType) {
            $type_list = [...$type_list, ...array_map(strval(...), $type->getTypes())];
        }
        else if ($type instanceof \ReflectionIntersectionType) {
            $it = array_map(strval(...), $type->getTypes());
            if ($sort) {
                sort($it);
            }
            $type_list []= implode('&', $it);
        }
    }
    $type_list = array_unique($type_list);
    if ($sort) {
        sort($type_list);
    }
    return implode('|', $type_list);
}

function get_primitive_type(mixed $x): ?string
{
    if ($x === null) return 'null';
    if ($x === false) return 'false';
    if ($x === true) return 'true';
    if (is_int($x)) return 'int';
    if (is_array($x)) return 'array';
    if (is_float($x)) return 'float';
    if (is_string($x)) return 'string';
    if (!($x instanceof \ast\Node)) {
        throw new \Exception('Unknown primitive type: ' . ($x ?? 'null'));
    }
    if ($x->kind === \ast\AST_ARRAY) return 'array';
    if ($x->kind === \ast\AST_CONST && $x->children['name']->children['name'] === 'null') return 'null';
    if ($x->kind === \ast\AST_CONST && $x->children['name']->children['name'] === 'true') return 'true';
    if ($x->kind === \ast\AST_CONST && $x->children['name']->children['name'] === 'false') return 'false';
    return null;
}

function type_has_supertype(ASTContext $ctx, array $types, array $supertypes): bool
{
    if (count($types) === 0 || count($supertypes) === 0) {
        return true;
    }
    foreach ($types as $type) {
        if ($type === null) {
            return true;
        }
        foreach ($supertypes as $supertype) {
            if ($supertype === null) {
                return true;
            }
            if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType &&
                !($supertype instanceof \ReflectionIntersectionType))
            {
                if (type_has_supertype($ctx, $type->getTypes(), [$supertype])) {
                    return true;
                }
                continue;
            }
            if ($supertype instanceof \ReflectionUnionType) {
                if (type_has_supertype($ctx, [$type], $supertype->getTypes())) {
                    return true;
                }
                continue;
            }
            $supertype_allows_null = false;
            $type_allows_null = false;
            if ($supertype instanceof \ReflectionNamedType) {
                $supertype_allows_null = $supertype->allowsNull();
                $supertype = $supertype->getName();
            }
            if ($type instanceof \ReflectionNamedType) {
                $type_allows_null = $type->allowsNull();
                $type = $type->getName();
            }
            $type = strtolower($type);
            $supertype = strtolower($supertype);
            if ($type === 'mixed' || $supertype === 'mixed') {
                return true;
            }
            if ($supertype instanceof \ReflectionIntersectionType) {
                if ($type instanceof \ReflectionIntersectionType) {
                    $types = $type->getTypes();
                }
                else {
                    $types = [$type];
                }
                $matches_all_types = true;
                foreach ($supertype->getTypes() as $st) {
                    $matches_supertype = false;
                    foreach ($types as $type) {
                        if (type_has_supertype($ctx, [$type], [$st])) {
                            $matches_supertype = true;
                            break;
                        }
                    }
                    if (!$matches_supertype) {
                        break;
                    }
                }
                if ($matches_supertype) {
                    return true;
                }
                continue;
            }
            if (($parent = $ctx->get_class($type)) !== null) {
                $parents_interfaces = $parent->getInterfaceNames();
                while (($parent = $parent->getParentClass()) !== false) {
                    $parents_interfaces []= $parent->getName();
                }
                $parents_interfaces = array_map(strtolower(...), $parents_interfaces);
            }
            else {
                $parents_interfaces = [];
            }

            if ($supertype === 'object' && $ctx->class_exists($type) ||
                $type === 'object' && $ctx->class_exists($supertype) ||
                $type === 'closure' && $supertype === 'callable' ||
                $type === 'int' && $supertype === 'float' ||
                $type === 'callable' && $supertype === 'closure' ||
                $type === 'string' && $supertype === 'callable' ||
                $type === 'bool' && in_array($supertype, ['false', 'true']) ||
                $supertype === 'bool' && in_array($type, ['false', 'true']) ||
                $type === 'null' && $supertype_allows_null ||
                $type_allows_null && $supertype_allows_null ||
                $type_allows_null && $supertype === 'null' ||
                $type === $supertype ||
                in_array($supertype, $parents_interfaces) ||
                $ctx->class_exists($type) &&
                in_array($supertype === 'string' ? 'stringable' : $supertype, $parents_interfaces))
            {
                return true;
            }
        }
    }
    return false;
}

function get_possible_types(ASTContext $ctx, mixed $node, bool $print_error=false,
    bool $is_in_assignment=false): array
{
    # Return value `[null]` means we don't know the type, `[]` means the type is invalid.
    # But sometimes we return `[null]` for invalid types to avoid duplicate error messages.

    assert($node !== null);
    if (!($node instanceof \ast\Node)) {
        return [get_primitive_type($node)];
    }
    if ($node->kind === \ast\AST_ARRAY) {
        return ['array'];
    }
    if ($node->kind === \ast\AST_CLASS_CONST || $node->kind === \ast\AST_STATIC_PROP) {
        if ($node->kind === \ast\AST_CLASS_CONST && !is_string($node->children['const']) ||
            $node->kind === \ast\AST_STATIC_PROP && !is_string($node->children['prop']))
        {
            return [null];
        }
        if ($node->children['class']->kind === \ast\AST_NAME) {
            $possible_classes = [$ctx->fq_class_name($node->children['class'], $print_error)];
        }
        else {
            $possible_classes = get_possible_types($ctx, $node->children['class'], $print_error);
        }
        if (in_array(null, $possible_classes)) {
            return [null];
        }
        $possible_types = [];
        foreach ($possible_classes as $possible_class) {
            $class = $ctx->get_class($possible_class);
            if ($class === null) {
                continue;
            }
            if ($node->kind === \ast\AST_CLASS_CONST) {
                $const_or_prop = $class->getReflectionConstant($node->children['const']);
            }
            else {
                if (!$class->hasProperty($node->children['prop'])) {
                    $const_or_prop = false;
                }
                else {
                    $const_or_prop = $class->getProperty($node->children['prop']);
                }
            }
            if ($const_or_prop === false) {
                continue;
            }
            $possible_types []= $const_or_prop->getType();
        }
        if (count($possible_types) > 0) {
            return $possible_types;
        }
        if ($print_error) {
            if ($node->kind === \ast\AST_CLASS_CONST) {
                $ctx->error("Undefined class constant `{$node->children['const']}`", $node);
            }
            else {
                $ctx->error("Undefined class property `{$node->children['prop']}`", $node);
            }
        }
        return [null];
    }
    if ($node->kind === \ast\AST_CONST) {
        return [$ctx->get_constant_type($node->children['name'])];
    }
    if ($node->kind === \ast\AST_METHOD_CALL || $node->kind === \ast\AST_STATIC_CALL) {
        if ($node->children['args']->kind === \ast\AST_CALLABLE_CONVERT) {
            return ['Closure'];
        }
        $possible_methods = get_possible_methods($ctx, $node, false);
        if ($possible_methods === null || count($possible_methods) === 0) {
            return [null];
        }
        return array_map(fn ($t) => $t->getReturnType(), $possible_methods);
    }
    if ($node->kind === \ast\AST_CALL) {
        if ($node->children['args']->kind === \ast\AST_CALLABLE_CONVERT) {
            return ['Closure'];
        }
        if ($node->children['expr']->kind === \ast\AST_NAME) {
            return [$ctx->get_function($node->children['expr'])?->getReturnType()];
        }
    }
    if ($node->kind === \ast\AST_NEW) {
        if ($node->children['class']->kind !== \ast\AST_NAME) {
            return [null];
        }
        return [$ctx->fq_class_name($node->children['class'], $print_error)];
    }
    if ($node->kind === \ast\AST_VAR) {
        if ($node->children['name'] instanceof \ast\Node) {
            return [null];
        }
        if (!array_key_exists($node->children['name'], $ctx->defined_variables)) {
            return [];
        }
        return $ctx->defined_variables[$node->children['name']]->possible_types;
    }
    if ($node->kind === \ast\AST_PROP) {
        if (!is_string($node->children['prop'])) {
            return [null];
        }
        $possible_expr_types = get_possible_types($ctx, $node->children['expr'], $print_error);
        if (count($possible_expr_types) === 0 || $possible_expr_types === [null]) {
            return [null];
        }
        $possible_types = [];
        foreach ($possible_expr_types as $possible_expr_type) {
            if ($possible_expr_type instanceof \ReflectionUnionType) {
                $possible_expr_type = array_map(fn ($t) => $t->getName(), $possible_expr_type->getTypes());
            }
            else if ($possible_expr_type instanceof \ReflectionNamedType) {
                $possible_expr_type = [$possible_expr_type->getName()];
            }
            else {
                $possible_expr_type = [$possible_expr_type];
            }
            foreach ($possible_expr_type as $type_name) {
                if (in_array(strtolower($type_name), ['stdclass', 'object', 'mixed'])) {
                    return [null];
                }
                if (in_array($type_name, NON_CLASS_TYPES)) {
                    continue;
                }
                $class = $ctx->get_class($type_name);
                if ($class === null) {
                    # Returning null to avoid error messages about properties when we already
                    # have one about the incorrect class name.
                    return [null];
                }
                if (!$is_in_assignment && $class->hasMethod('__get') ||
                    $is_in_assignment && $class->hasMethod('__set'))
                {
                    return [null];
                }
                if (!$class->hasProperty($node->children['prop'])) {
                    continue;
                }
                $prop = $class->getProperty($node->children['prop']);
                if ($prop->getModifiers() & \ast\flags\MODIFIER_STATIC) {
                    if ($print_error) {
                        $ctx->error("Non-static access to static property `{$prop->getName()}`", $node);
                    }
                    return [null];
                }
                $possible_types []= $prop->getType();
            }
        }
        if (count($possible_types) === 0) {
            $possible_expr_types_str = type_to_string($possible_expr_types);
            if ($print_error) {
                $ctx->error("Variable of type `$possible_expr_types_str` does not have property " .
                    "`{$node->children['prop']}`", $node);
            }
            return [null];
        }
        return array_unique($possible_types);
    }
    return [null];
}

function validate_arguments(ASTContext $ctx, ?\ReflectionFunctionAbstract $function, \ast\Node $node): void
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
            if ($arg->children['expr'] instanceof \ast\Node &&
                $arg->children['expr']->kind === \ast\AST_VAR)
            {
                $var = $arg->children['expr']->children['name'];
                $arg_types = $ctx->defined_variables[$var]->possible_types ?? null;
            }
            if ($function !== null) {
                $arg_name = $arg->children['name'];
                foreach ($function->getParameters() as $p) {
                    if ($p->getName() === $arg_name) {
                        $parameter = $p;
                    }
                }
                if ($parameter === null && !$function->isVariadic()) {
                    $ctx->error("Invalid argument name `$arg_name`", $arg);
                    continue;
                }
            }
        }
        else if ($function !== null) {
            if ($index >= count($function->getParameters())) {
                if (!$function->isVariadic()) {
                    $ctx->error("Too many arguments for function `{$function->getName()}`", $node);
                    break;
                }
                $parameter = null;
            }
            else {
                $parameter = $function->getParameters()[$index];
            }
        }
        if ($parameter?->isPassedByReference() && (!($arg instanceof \ast\Node) ||
            !in_array($arg->kind, [\ast\AST_VAR, \ast\AST_PROP, \ast\AST_DIM])))
        {
            $index += 1;
            $ctx->error("In the call to `{$function->getName()}`, the expression in argument $index " .
                "cannot be passed by reference", $node);
            return;
        }
        if ($parameter === null) {
            continue;
        }
        $parameter_type = $parameter->getType();
        if ($parameter_type === null) {
            continue;
        }
        $arg_types = get_possible_types($ctx, $arg);
        if (!type_has_supertype($ctx, $arg_types, [$parameter_type])) {
            $arg_types_str = type_to_string($arg_types);
            $parameter_types_str = type_to_string([$parameter_type]);
            $index += 1;
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

function get_possible_methods(ASTContext $ctx, \ast\Node $node, bool $print_error): ?array
{
    # Return value: `null` means unknown but possibly valid method or we want to suppress further
    # error messages, `[]` means we know that the method does not exist.

    if (!is_string($node->children['method'])) {
        return null;
    }
    if (array_key_exists('class', $node->children)) { # Static call
        $is_static_call = true;
        if ($node->children['class']->kind === \ast\AST_NAME) { # Example: `Klasse::method()`
            $possible_types = [$ctx->fq_class_name($node->children['class'])];
        }
        else { # Example: `$variable::method()`
            $possible_types = get_possible_types($ctx, $node->children['class']);
        }
    }
    else { # Non-static call. Example: `$this->method()`
        $is_static_call = false;
        $possible_types = get_possible_types($ctx, $node->children['expr']);
    }
    if ($possible_types === []) {
        return null;
    }
    $possible_methods = [];
    foreach ($possible_types as $possible_type) {
        if ($possible_type instanceof \ReflectionNamedType) {
            $possible_type = [$possible_type->getName()];
        }
        else if ($possible_type instanceof \ReflectionUnionType) {
            $possible_type = array_map(fn ($t) => $t->getName(), $possible_type->getTypes());
        }
        else if (is_string($possible_type)) {
            $possible_type = [$possible_type];
        }
        else {
            return null;
        }
        foreach ($possible_type as $type_name) {
            if (in_array($type_name, NON_CLASS_TYPES)) {
                continue;
            }
            $class = $ctx->get_class($type_name);
            if ($class === null) {
                if ($print_error) {
                    $ctx->error("Undefined class `$type_name`", $node);
                }
                return null;
            }
            if ($is_static_call && $class->hasMethod('__callStatic') ||
                !$is_static_call && $class->hasMethod('__call'))
            {
                return null;
            }
            if ($class->hasMethod($node->children['method'])) {
                $possible_methods []= $class->getMethod($node->children['method']);
            }
        }
    }
    return $possible_methods;
}

class AST_ReflectionIntersectionType extends \ReflectionIntersectionType
{
    private array $types = [];

    function __construct(private ASTContext $ctx, private \ast\Node $node)
    {
        foreach ($this->node->children as $child) {
            $this->types []= AST_ReflectionNamedType::try_create($this->ctx, $child, false);
        }
    }

    function getTypes(): array
    {
        return $this->types;
    }

    function __toString()
    {
        return implode('&', array_map(fn ($t) => $t->getName(), $this->types));
    }
}

class AST_ReflectionUnionType extends \ReflectionUnionType
{
    private array $types = [];

    function __construct(private ASTContext $ctx, \ast\Node $node, bool $has_default_null)
    {
        foreach ($node->children as $child) {
            if ($child->kind === \ast\AST_TYPE_INTERSECTION) {
                $this->types []= new AST_ReflectionIntersectionType($ctx, $child);
                continue;
            }
            if (count($node->children) > 0) {
                if ($child->flags === \ast\flags\TYPE_MIXED) {
                    $ctx->error("Type hint `mixed` must be standalone", $node);
                    break;
                }
                if ($child->flags === \ast\flags\TYPE_VOID) {
                    $ctx->error("Type hint `void` must be standalone", $node);
                    break;
                }
            }
            $this->types []= AST_ReflectionNamedType::try_create($ctx, $child, false);
        }
        if ($has_default_null) {
            $this->types []= AST_ReflectionNamedType::try_create($ctx, 'null', false);
        }
    }

    function getTypes(): array
    {
        return $this->types;
    }

    function __toString()
    {
        return implode('|', array_map(fn ($t) => $t->getName(), $this->types));
    }
}

class AST_ReflectionNamedType extends \ReflectionNamedType
{
    private string $type_name;
    public bool $allows_null;

    static function try_create(ASTContext $ctx, null|string|\ReflectionClass|\ast\Node $node,
        bool $is_nullable): ?\ReflectionNamedType
    {
        try {
            return new self(...func_get_args());
        }
        catch (\TypeError) {
            return null;
        }
    }

    function __construct(ASTContext $ctx, private string|\ReflectionClass|\ast\Node $node,
        bool $is_nullable)
    {
        if (is_string($node)) {
            $this->type_name = $node;
        }
        else if ($node instanceof \ReflectionClass) {
            $this->type_name = $node->getName();
        }
        else if ($node->kind === \ast\AST_TYPE && $node->flags === \ast\flags\TYPE_STATIC) {
            if ($ctx->class === null) {
                $ctx->error('Cannot access `static` when no class scope is active', $node);
            }
            $this->type_name = $ctx->class?->getName();
        }
        else if ($node->kind === \ast\AST_TYPE) {
            $this->type_name = match ($node->flags) {
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
                \ast\flags\TYPE_MIXED => 'mixed',
                \ast\flags\TYPE_NEVER => 'never',
                default => throw new \Exception('Unknown type flag')
            };
        }
        else if ($node->kind === \ast\AST_NAME) {
            $type_name = $ctx->fq_class_name($node);
            if ($type_name === null) {
                throw new \TypeError;
            }
            $this->type_name = $type_name;
            if (!$ctx->class_exists($this->type_name) && !interface_exists($this->type_name)) {
                $ctx->error("Undefined type `{$this->type_name}` in type hint", $node);
            }
        }
        $this->allows_null =
            $is_nullable || $node instanceof \ast\Node && (
            $node->kind === \ast\AST_TYPE && $node->flags === \ast\flags\TYPE_NULL ||
            $node->kind === \ast\AST_TYPE && $node->flags === \ast\flags\TYPE_MIXED);
    }

    function isBuiltin(): bool
    {
        return $this->node->kind === \ast\AST_TYPE;
    }

    function allowsNull(): bool
    {
        return $this->allows_null;
    }

    function getName(): string
    {
        return $this->type_name;
    }

    function __toString()
    {
        # This method is needed to support `array_unique`
        return ($this->allows_null && $this->type_name !== 'null' ? '?' : '') . $this->type_name;
    }
}

class AST_ReflectionProperty extends \ReflectionProperty
{
    function __construct(private string $_name, private mixed $default, private ?\ReflectionType $type,
        private int $flags)
    {}

    function getName(): string
    {
        return $this->_name;
    }

    function getType(): ?\ReflectionType
    {
        return $this->type;
    }

    function getModifiers(): int
    {
        return $this->flags;
    }
}

class AST_ReflectionClassConstant extends \ReflectionClassConstant
{
    function __construct(private string $_name, private ?\ReflectionType $type, private int $flags)
    {}

    function getName(): string
    {
        return $this->_name;
    }

    function getType(): ?\ReflectionType
    {
        return $this->type;
    }

    function getModifiers(): int
    {
        return $this->flags;
    }
}

class AST_ReflectionParameter extends \ReflectionParameter
{
    function __construct(private \ast\Node $node, private ?\ReflectionType $type)
    {}

    function getName(): string
    {
        return $this->node->children['name'];
    }

    function isPassedByReference(): bool
    {
        return ($this->node->flags & \ast\flags\PARAM_REF) !== 0;
    }

    function getType(): ?\ReflectionType
    {
        return $this->type;
    }

    function isOptional(): bool
    {
        return $this->node->children['default'] !== null || ($this->node->flags & \ast\flags\PARAM_VARIADIC);
    }

    function isVariadic(): bool
    {
        return ($this->node->flags & \ast\flags\PARAM_VARIADIC) !== 0;
    }
}

trait AST_ReflectionFunctionAbstract
{
    private string $function_name;
    private array $parameters = [];
    private null|\ReflectionType|\ReflectionNamedType $return_type = null;
    private bool $is_variadic = false;
    private ?\ReflectionClass $declaring_class;
    private string $file_name;
    private string $namespace;
    private array $use_aliases;

    function __construct(private ASTContext $ctx, private \ast\Node $node)
    {
        $this->file_name = $ctx->file_name;
        $this->namespace = $ctx->namespace;
        $this->use_aliases = $ctx->use_aliases;
    }

    function initialize()
    {
        $this->ctx->file_name = $this->file_name;
        $this->ctx->namespace = $this->namespace;
        $this->ctx->use_aliases = $this->use_aliases;
        if ($this->node->flags & \ast\flags\MODIFIER_ABSTRACT) {
            if ($this->node->flags & \ast\flags\MODIFIER_PRIVATE) {
                $this->ctx->error("Abstract method `{$this->node->children['name']}` cannot be private",
                    $this->node);
            }
            if ($this->node->children['stmts'] !== null) {
                $this->ctx->error("Abstract method `{$this->node->children['name']}` cannot have a body",
                    $this->node);
            }
            if ($this->ctx->class->isInterface() & \ast\flags\CLASS_INTERFACE) {
                $this->ctx->error("Interface method `{$this->node->children['name']}` must not be abstract",
                    $this->node);
            }
        }
        else if ($this->node->children['stmts'] === null && !$this->ctx->class->isInterface()) {
            $this->ctx->error("Non-abstract method `{$this->node->children['name']}` must have a body",
                $this->node);
        }
        if ($this->ctx->class?->isInterface() && (($this->node->flags & \ast\flags\MODIFIER_PROTECTED) ||
            $this->node->flags & \ast\flags\MODIFIER_PRIVATE)) {
            $this->ctx->error("Interface method `{$this->node->children['name']}` must be public",
                $this->node);
        }
        $this->function_name = $this->ctx->class === null ?
            $this->ctx->namespace . $this->node->children['name'] : $this->node->children['name'];
        $this->declaring_class = $this->ctx->class;
        for ($i = 0; $i < count($this->node->children['params']->children); ++$i) {
            $param = $this->node->children['params']->children[$i];
            $type_hint = $this->ctx->reflection_type_from_ast($param->children['type']);
            if (($param->flags & \ast\flags\PARAM_VARIADIC) && $param->children['default'] !== null) {
                $this->ctx->error("A variadic parameter cannot have a default value", $param);
            }
            $this->parameters []= new AST_ReflectionParameter($param, $type_hint);
            if (($param->flags & \ast\flags\PARAM_VARIADIC)) {
                $this->is_variadic = true;
                if ($i < count($this->node->children['params']->children) - 1) {
                    $this->ctx->error("Only the last parameter can be variadic", $this->node);
                }
            }
            if ($param->children['default'] === null) {
                continue;
            }
            $default_type = get_primitive_type($param->children['default']);
            if (!type_has_supertype($this->ctx, [$default_type], [$type_hint])) {
                $type_str = type_to_string([$type_hint]);
                $this->ctx->error(
                    "Mismatch between type hint `$type_str` and default value type `$default_type` " .
                    "for parameter `{$param->children['name']}`", $param
                );
            }
        }
        if ($this->node->children['returnType'] !== null) {
            $this->return_type = $this->ctx->reflection_type_from_ast($this->node->children['returnType']);
        }
    }

    function is_return_required(): bool
    {
        return
            $this->node->children['stmts'] !== null &&
            ($this->node->flags & \ast\flags\MODIFIER_ABSTRACT) === 0 &&
            ($this->node->flags & \ast\flags\FUNC_GENERATOR) === 0 &&
            $this->return_type !== null &&
            (!$this->return_type instanceof \ReflectionNamedType ||
            !in_array($this->return_type->getName(), ['void', 'never']));
    }

    function __toString()  # Must implement this abstract method
    {
        return $this->function_name;
    }

    function isAbstract(): bool
    {
        return ($this->node->flags & \ast\flags\MODIFIER_ABSTRACT) !== 0;
    }

    function isGenerator(): bool
    {
        return ($this->node->flags & \ast\flags\FUNC_GENERATOR) !== 0;
    }

    function getReturnType(): ?\ReflectionType
    {
        return $this->return_type;
    }

    function getName(): string
    {
        return $this->function_name;
    }

    function isVariadic(): bool
    {
        return $this->is_variadic;
    }

    function getParameters(): array
    {
        return $this->parameters;
    }
}

class AST_ReflectionFunction extends \ReflectionFunctionAbstract
{
    use AST_ReflectionFunctionAbstract;

    function getDeclaringClass(): ?\ReflectionClass
    {
        return $this->declaring_class;
    }
}

class MethodMadeNonAbstract extends \ReflectionMethod
{
    function __construct(private \ReflectionMethod $method)
    {}

    function isStatic(): bool
    {
        return $this->method->isStatic();
    }

    function getParameters(): array
    {
        return $this->method->getParameters();
    }

    function getModifiers(): int
    {
        return $this->method->getModifiers() & ~\ast\flags\MODIFIER_ABSTRACT;
    }

    function is_return_required(): bool
    {
        return false;
    }
}

class AST_ReflectionMethod extends \ReflectionMethod
{
    use AST_ReflectionFunctionAbstract;

    function isStatic(): bool
    {
        return ($this->node->flags & \ast\flags\MODIFIER_STATIC) !== 0;
    }

    function getDeclaringClass(): \ReflectionClass
    {
        return $this->declaring_class;
    }

    function getModifiers(): int
    {
        return $this->node->flags | $this->declaring_class->isInterface() * \ast\flags\MODIFIER_ABSTRACT;
    }
}

class AST_ReflectionClass extends \ReflectionClass
{
    private array $properties = [];
    private array $constants = [];
    private array $methods = [];
    private array $interface_names = [];
    private null|\ReflectionClass|AST_ReflectionClass $extends = null;
    private string $file_name;
    private string $namespace;
    private bool $is_initialized = false;
    private array $use_aliases;

    function __construct(private ASTContext $ctx, private \ast\Node $node)
    {
        $this->file_name = $ctx->file_name;
        $this->namespace = $ctx->namespace;
        $this->use_aliases = $ctx->use_aliases;
    }

    private function process_class_stmt(\ast\Node $stmt, array $interface_methods,
        null|false|\ReflectionType $backing_type, ?\ReflectionType $enum_type)
    {
        if ($stmt->kind === \ast\AST_PROP_GROUP) {
            if ($this->node->flags & \ast\flags\CLASS_INTERFACE) {
                $this->ctx->error("Interfaces may not include properties", $stmt);
                return;
            }
            $type_hint = $this->ctx->reflection_type_from_ast($stmt->children['type']);
            foreach ($stmt->children['props']->children as $prop) {
                $name = $prop->children['name'];
                if (array_key_exists($name, $this->properties)) {
                    $this->ctx->error("Cannot redefine property `$name`", $stmt);
                    return;
                }
                if (($prop->flags & \ast\flags\MODIFIER_READONLY) && $prop->children['default'] !== null) {
                    $this->ctx->error("Readonly non-parameter property `$name` cannot have a default value",
                        $prop);
                }
                if ($prop->flags & \ast\flags\MODIFIER_READONLY && $type_hint === null) {
                    $this->ctx->error("Readonly property `$name` must have a type hint", $prop);
                }
                $default = $prop->children['default'];
                $this->properties[$name] = new AST_ReflectionProperty($name, $default, $type_hint,
                    $stmt->flags);
                if ($default === null) {
                    continue;
                }
                $default_type = get_primitive_type($default);
                if (!type_has_supertype($this->ctx, [$default_type], [$type_hint])) {
                    $type_str = type_to_string([$type_hint]);
                    $this->ctx->error(
                        "Mismatch between type hint `$type_str` and default value type `$default_type` " .
                        "for property `$name`", $prop
                    );
                }
            }
        }
        else if ($stmt->kind === \ast\AST_CLASS_CONST_GROUP) {
            $type_hint = $this->ctx->reflection_type_from_ast($stmt->children['type']);
            foreach ($stmt->children['const']->children as $const) {
                $name = $const->children['name'];
                if (array_key_exists($name, $this->constants)) {
                    $this->ctx->error("Cannot redefine class constant `$name`", $stmt);
                    return;
                }
                $value_type = get_primitive_type($const->children['value']);
                $value_type = AST_ReflectionNamedType::try_create($this->ctx, $value_type, false);
                if (!type_has_supertype($this->ctx, [$value_type], [$type_hint])) {
                    $type_str = type_to_string([$type_hint]);
                    $this->ctx->error(
                        "Mismatch between type hint `$type_str` and value type `$value_type` " .
                        "for constant `$name`", $const
                    );
                }
                $this->constants[$name] = new AST_ReflectionClassConstant($name, $value_type, $stmt->flags);
            }
        }
        else if ($stmt->kind === \ast\AST_METHOD) {
            if ($this->node->flags & \ast\flags\CLASS_INTERFACE && $stmt->children['stmts'] !== null) {
                $this->ctx->error("The interface method must not have a body", $stmt);
            }
            $name = strtolower($stmt->children['name']);
            if ($this->extends?->hasMethod($name) &&
                $this->extends?->getModifiers() & \ast\flags\MODIFIER_FINAL)
            {
                $this->ctx->error("Cannot redeclare final method `$name`", $this->node);
                return;
            }
            if (array_key_exists($name, $this->methods)) {
                $this->ctx->error("Cannot redeclare method `{$stmt->children['name']}`", $stmt);
                return;
            }
            $method = new AST_ReflectionMethod($this->ctx, $stmt);
            $method->initialize();
            if (array_key_exists(strtolower($stmt->children['name']), $interface_methods)) {
                $imethod = $interface_methods[strtolower($stmt->children['name'])];
                if (($imethod->getModifiers() ^ $method->getModifiers()) & ~\ast\flags\MODIFIER_ABSTRACT) {
                    $this->ctx->error("Method `{$method->getName()}` has different access modifiers " .
                        "compared to the definition in the interface", $this->node);
                }
                $ip = $imethod->getParameters();
                $p = $method->getParameters();
                for ($i = 0; $i < count($ip); ++$i) {
                    if (!array_key_exists($i, $p)) {
                        $this->ctx->error("Method `{$method->getName()}` has fewer parameters " .
                            "than the definition in the interface", $this->node);
                        break;
                    }
                    if ($p[$i]->isVariadic()) {
                        break;
                    }
                    if ($p[$i]->getType() === null || $p[$i]->getType() instanceof \ReflectionNamedType &&
                        $p[$i]->getType()->getName() === 'mixed')
                    {
                        continue;
                    }
                    if (!array_key_exists($i, $p) ||
                        strval($ip[$i]->getType()) !== strval($p[$i]->getType()))
                    {
                        $this->ctx->error("Method `{$method->getName()}` has different parameter types " .
                            "compared to the definition in the interface", $this->node);
                    }
                }
                if ($i < count($p) && !$p[$i]->isVariadic()) {
                    $this->ctx->error("Method `{$method->getName()}` has more parameters " .
                        "than the definition in the interface", $this->node);
                }
                if ($imethod->getReturnType() !== null) {
                    $iret = type_to_string([$imethod->getReturnType()], true);
                    $ret = type_to_string([$method->getReturnType()], true);
                    if($iret !== $ret) {
                    $this->ctx->error("Method `{$method->getName()}` has a different return type " .
                        "compared to the definition in the interface", $this->node);
                    }
                }
            }
            $this->methods[$name] = $method;
            if ($stmt->children['name'] !== '__construct') {
                return;
            }
            foreach ($stmt->children['params']->children as $param) {
                if (!($param->flags & \ast\flags\MODIFIER_PUBLIC) &&
                    !($param->flags & \ast\flags\MODIFIER_PROTECTED) &&
                    !($param->flags & \ast\flags\MODIFIER_PRIVATE))
                {
                    continue;
                }
                $name = $param->children['name'];
                $type_hint = $this->ctx->reflection_type_from_ast($param->children['type']);
                if ($param->flags & \ast\flags\MODIFIER_READONLY && $type_hint === null) {
                    $this->ctx->error("Readonly property `$name` must have a type hint", $param);
                }
                $this->properties[$name] = new AST_ReflectionProperty($name, $param->children['default'],
                    $type_hint, $param->flags);
            }
        }
        else if ($stmt->kind === \ast\AST_ENUM_CASE) {
            if (!($this->node->flags & \ast\flags\CLASS_ENUM)) {
                $this->ctx->error('`case` can only be used in enums', $stmt);
                return;
            }
            $name = $stmt->children['name'];
            $value = $stmt->children['expr'];
            if ($backing_type === false && $value !== null) {
                $this->ctx->error("Case `$name` of a non-backed enum must not have a value", $stmt);
            }
            else if ($backing_type instanceof \ReflectionType && $value === null) {
                $this->ctx->error("Case `$name` of a backed enum must have a value", $stmt);
            }
            else if ($backing_type instanceof \ReflectionType &&
                ($backing_type?->getName() === 'string') !== is_string($value))
            {
                $this->ctx->error("Type of case `$name` does not match the enum's backing type", $stmt);
            }
            $this->constants[$name] = new AST_ReflectionClassConstant($name, $enum_type,
                \ast\flags\MODIFIER_PUBLIC | \ast\flags\MODIFIER_READONLY);
        }
    }

    function initialize()
    {
        if ($this->is_initialized) {
            return;
        }
        $this->is_initialized = true;
        $this->ctx->class = $this;
        $this->ctx->file_name = $this->file_name;
        $this->ctx->namespace = $this->namespace;
        $this->ctx->use_aliases = $this->use_aliases;

        ### Processing interfaces

        $interface_methods = [];
        $interface_constants = [];
        $implements_lowercase = [];
        foreach ($this->node->children['implements']?->children ?? [] as $interface) {
            $interface_name = $this->ctx->fq_class_name($interface);
            $class = $this->ctx->get_class($interface_name);
            if ($class === null) {
                $this->ctx->error("Undefined interface `$interface_name`", $interface);
                continue;
            }
            if ($class instanceof AST_ReflectionClass) {
                $class->initialize();
            }

            if (in_array(strtolower($interface_name), $implements_lowercase)) {
                $this->ctx->error("Duplicate specification of interface `$interface_name`", $interface);
            }
            $implements_lowercase []= strtolower($interface_name);

            $this->interface_names = [
                ...$this->interface_names, $class->getName(), ...$class->getInterfaceNames()
            ];
            foreach ($class->getMethods() as $method) {
                $interface_methods[strtolower($method->getName())] = $method;
            }
            foreach ($class->getReflectionConstants() as $constant) {
                $interface_constants[$constant->getName()] = $constant;
            }
        }

        ### Processing the parent class

        $parent_methods = [];
        $parent_constants = [];
        $parent_properties = [];
        if ($this->node->children['extends'] !== null) {
            $class_name = $this->ctx->fq_class_name($this->node->children['extends']);
            if (!$this->ctx->class_exists($class_name)) {
                $this->ctx->error(
                    "Parent class `$class_name` does not exist", $this->node->children['extends']);
            }
            else {
                $this->extends = $this->ctx->get_class($class_name);
                if ($this->extends instanceof AST_ReflectionClass) {
                    $this->extends->initialize(); # Must come before `process_class_stmts(…)`
                }
                $this->interface_names = [
                    ...$this->interface_names, ...$this->extends->getInterfaceNames()
                ];
                if ($this->extends->isFinal()) {
                    $this->ctx->error("Cannot inherit from final class `{$this->extends->getName()}`",
                        $this->node);
                }
                foreach ($this->extends->getMethods() as $method) {
                    $parent_methods[strtolower($method->getName())] = $method;
                }
                foreach ($this->extends->getProperties() as $property) {
                    $parent_properties[$property->getName()] = $property;
                }
                foreach ($this->extends->getReflectionConstants() as $constant) {
                    $parent_constants[$constant->getName()] = $constant;
                }
            }
        }

        ### Processing methods, properties, constants

        if ($this->node->flags & \ast\flags\CLASS_ENUM) {
            $enum_type = AST_ReflectionNamedType::try_create($this->ctx, $this, false);
            $backing_type = $this->ctx->reflection_type_from_ast($this->node->children['type']);
            if ($backing_type !== null) { # Must come after the check for abstract methods
                $this->properties['value'] = new AST_ReflectionProperty(
                    'value', null, $backing_type, \ast\flags\MODIFIER_PUBLIC | \ast\flags\MODIFIER_READONLY
                );
                foreach ((new \ReflectionClass(\BackedEnum::class))->getMethods() as $method) {
                    $this->methods[strtolower($method->getName())] = new MethodMadeNonAbstract($method);
                }
            }
            if ($backing_type instanceof \ReflectionNamedType &&
                !in_array($backing_type->getName(), ['int', 'string']))
            {
                $this->ctx->error(
                    "Enum backing type must be `int` or `string`, got `{$backing_type->getName()}`",
                    $this->node
                );
                $backing_type = null;
            }
        }
        else {
            $enum_type = null;
            $backing_type = false;
        }
        foreach ($this->node->children['stmts']->children as $stmt) {
            $this->process_class_stmt($stmt, $interface_methods, $backing_type, $enum_type);
        }

        ### Processing traits

        $trait_methods = [];
        $ignored_trait_methods = [];
        foreach ($this->node->children['stmts']->children as $stmt) {
            if ($stmt->kind !== \ast\AST_USE_TRAIT) {
                continue;
            }
            foreach ($stmt->children['adaptations']?->children ?? [] as $adaptation) {
                $class_name = $adaptation->children['method']->children['class']->children['name'];
                $class = $this->ctx->get_class($class_name);
                if ($class === null) {
                    $this->ctx->error("Undefined trait `$class_name`", $adaptation);
                    continue;
                }
                $method = $adaptation->children['method']->children['method'];
                if (!$class->hasMethod($method)) {
                    $this->ctx->error("Trait `$class_name` does not have method `$method`", $adaptation);
                }
                foreach ($adaptation->children['insteadof']->children as $insteadof) {
                    $insteadof_name = $insteadof->children['name'];
                    if (!$this->ctx->get_class($insteadof_name)?->isTrait()) {
                        $this->ctx->error("Undefined trait `$insteadof_name`", $insteadof);
                    }
                }
                $ignored_trait_methods[$insteadof_name] []= $method;
            }
            foreach ($stmt->children['traits']->children as $trait_node) {
                $trait_name = $this->ctx->fq_class_name($trait_node);
                $trait = $this->ctx->get_class($trait_name);
                if (!$trait?->isTrait()) {
                    $this->ctx->error("Trait `$trait_name` does not exist", $trait_node);
                    continue;
                }
                if ($trait instanceof AST_ReflectionClass) {
                    $trait->initialize();
                }
                foreach ($trait->getProperties() as $p) {
                    $this->properties[$p->getName()] = $p;
                }
                foreach ($trait->getReflectionConstants() as $c) {
                    $this->constants[$c->getName()] = $c;
                }
                foreach ($trait->getMethods() as $method) {
                    if (in_array($method->getName(), $ignored_trait_methods[$trait_name] ?? [])) {
                        continue;
                    }
                    if (array_key_exists($method->getName(), $trait_methods) &&
                        !array_key_exists($method->getName(), $this->methods))
                    {
                        $this->ctx->error(
                            "Method `{$method->getName()}` is defined in multiple traits", $trait_node
                        );
                        continue;
                    }
                    $trait_methods[strtolower($method->getName())] = $method;
                }
            }
        }

        ### Combining methods, constants, properties

        $this->methods = $this->methods + $trait_methods + $parent_methods + $interface_methods;
        $this->constants = $this->constants + $parent_constants + $interface_constants;
        $this->properties = $this->properties + $parent_properties;

        ### Miscellaneous

        if (!($this->node->flags & \ast\flags\MODIFIER_ABSTRACT) &&
            !($this->node->flags & \ast\flags\CLASS_INTERFACE))
        {
            foreach ($this->methods as $method) {
                if (!($method->getModifiers() & \ast\flags\MODIFIER_ABSTRACT)) {
                    continue;
                }
                $this->ctx->error(
                    "Non-abstract class contains the abstract method `{$method->getName()}`", $this->node
                );
            }
        }

        if (array_key_exists('__tostring', $this->methods)) {
            $this->interface_names []= \Stringable::class;
        }
    }

    function isInterface(): bool
    {
        return ($this->node->flags & \ast\flags\CLASS_INTERFACE) !== 0;
    }

    function isTrait(): bool
    {
        return ($this->node->flags & \ast\flags\CLASS_TRAIT) !== 0;
    }

    function isAbstract(): bool
    {
        return ($this->node->flags & \ast\flags\MODIFIER_ABSTRACT) !== 0;
    }

    function isFinal(): bool
    {
        return ($this->node->flags & \ast\flags\MODIFIER_FINAL) !== 0;
    }

    function getParentClass(): false|\ReflectionClass
    {
        return $this->extends === null ? false : $this->extends;
    }

    function hasConstant(string $name): bool
    {
        return array_key_exists($name, $this->constants);
    }

    function getReflectionConstant(string $name): AST_ReflectionClassConstant|false
    {
        return $this->constants[$name] ?? false;
    }

    function getReflectionConstants(?int $filter=null): array
    {
        return $this->constants;
    }

    function getInterfaceNames(): array
    {
        return $this->interface_names;
    }

    function getConstructor(): ?\ReflectionMethod
    {
        return $this->methods['__construct'] ?? $this->extends?->getConstructor();
    }

    function getName(): string
    {
        return $this->namespace . $this->node->children['name'];
    }

    function hasProperty(string $name): bool
    {
        return array_key_exists($name, $this->properties);
    }

    function getProperties(?int $filter=null): array
    {
        return $this->properties;
    }

    function getProperty(string $name): \ReflectionProperty
    {
        return $this->properties[$name];
    }

    function hasMethod(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->methods);
    }

    function getMethod(string $name): \ReflectionMethod
    {
        return $this->methods[strtolower($name)] ?? throw new \Exception;
    }

    function getMethods(?int $filter=null): array
    {
        return array_values($this->methods);
    }
}

function add_variables_in_node(ASTContext $ctx, \ast\Node $node): void
{
    # This function is called on the `value` argument in `foreach` loops and on
    # arrays that are assigned a value (e.g. `[$a, $b] = [1, 2]`).

    if ($node->kind === \ast\AST_ARRAY) {
        foreach ($node->children as $child) {
            if ($child->children['key'] instanceof \ast\Node) {
                add_variables_in_node($ctx, $child->children['key']);
            }
            if ($child->children['value'] instanceof \ast\Node) {
                add_variables_in_node($ctx, $child->children['value']);
            }
        }
    }
    else if ($node->kind === \ast\AST_REF) {
        add_variables_in_node($ctx, $node->children['var']);
    }
    else if ($node->kind === \ast\AST_VAR && is_string($node->children['name'])) {
        $ctx->add_defined_variable($node->children['name'], [null]);
    }
}

function find_defined_variables(ASTContext $ctx, \ast\Node $node): void
{
    # Variables may be defined or have their type changed below their first usage. Therefore
    # we have to determine all defined variables of the scope before starting to run type checks.

    if ($node->kind === \ast\AST_INSTANCEOF) {
        if ($node->children['expr']->kind !== \ast\AST_VAR) {
            goto end;
        }
        $class_name = $ctx->fq_class_name($node->children['class']);
        if ($ctx->get_class($class_name) === null) {
            $ctx->error("Undefined class $class_name", $node);
            goto end;
        }
        $var = $node->children['expr']->children['name'];
        if (array_key_exists($var, $ctx->defined_variables)) {
            $ctx->add_defined_variable($var, [$class_name]);
        }
    }
    else if ($node->kind === \ast\AST_CATCH) {
        if ($node->children['var'] === null) {
            goto end;
        }
        $possible_types = [];
        foreach ($node->children['class']->children as $class_node) {
            $class = $ctx->fq_class_name($class_node);
            if ($ctx->class_exists($class) && !interface_exists($class)) {
                $possible_types []= $class;
            }
        }
        $var = $node->children['var']->children['name'];
        $ctx->add_defined_variable($var, $possible_types);
    }
    else if ($node->kind === \ast\AST_ASSIGN) {
        if ($node->children['var']->kind === \ast\AST_ARRAY) {
            add_variables_in_node($ctx, $node->children['var']);
            goto end;
        }
        $expr_types = get_possible_types($ctx, $node->children['expr']);
        if ($node->children['var']->kind === \ast\AST_VAR &&
            is_string($node->children['var']->children['name']))
        {
            $ctx->add_defined_variable($node->children['var']->children['name'], $expr_types);
        }
    }
    else if ($node->kind === \ast\AST_GLOBAL) {
        if (!is_string($node->children['var']->children['name'])) {
            goto end;
        }
        $var = $node->children['var']->children['name'];
        $ctx->add_defined_variable($var, $ctx->global_scope_variables[$var]->possible_types);
    }
    else if ($node->kind === \ast\AST_STATIC) {
        $var = $node->children['var']->children['name'];
        if ($node->children['default'] === null) {
            $ctx->add_defined_variable($var, ['null']);
        }
        else {
            $ctx->add_defined_variable($var, get_possible_types($ctx, $node->children['default']));
        }
    }
    else if ($node->kind === \ast\AST_CLOSURE) {
        foreach ($node->children['uses']->children ?? [] as $use) {
            if ($use->flags === \ast\flags\CLOSURE_USE_REF) {
                $ctx->add_defined_variable($use->children['name'], [null]);
            }
        }
        return; # Not visiting the closure statements
    }
    else if ($node->kind === \ast\AST_FOREACH) {
        if (is_string($node->children['key']?->children['name'] ?? null)) {
            $ctx->add_defined_variable($node->children['key']->children['name'], [null]);
        }
        add_variables_in_node($ctx, $node->children['value']);
    }
    else if (in_array($node->kind, [\ast\AST_CALL, \ast\AST_METHOD_CALL, \ast\AST_STATIC_CALL])) {
        if ($node->children['args'] === \ast\AST_CALLABLE_CONVERT) {
            goto end;
        }
        if ($node->kind === \ast\AST_CALL) {
            if ($node->children['expr']->kind === \ast\AST_NAME) {
                $function = $ctx->get_function($node->children['expr']);
            }
            else {
                $function = null;
            }
        }
        else {
            $function = get_possible_methods($ctx, $node, false)[0] ?? null;
        }
        foreach ($node->children['args']->children as $index => $arg) {
            if (!$arg instanceof \ast\Node) {
                continue;
            }
            $parameter = null;
            if ($arg->kind === \ast\AST_NAMED_ARG) {
                if (!($arg->children['expr'] instanceof \ast\Node) ||
                    $arg->children['expr']->kind !== \ast\AST_VAR)
                {
                    continue;
                }
                $var = $arg->children['expr']->children['name'];
                if ($function !== null) {
                    foreach ($function->getParameters() as $p) {
                        if ($p->getName() === $arg->children['name']) {
                            $parameter = $p;
                        }
                    }
                }
            }
            else if ($arg->kind == \ast\AST_VAR) {
                if ($arg->children['name'] instanceof \ast\Node) {
                    continue;
                }
                $var = $arg->children['name'];
                if ($function !== null) {
                    if ($index >= count($function->getParameters())) {
                        break;
                    }
                    else {
                        $parameter = $function->getParameters()[$index];
                    }
                }
            }
            else {
                continue;
            }
            if ($parameter === null) {
                $ctx->add_defined_variable($var, [null]);
            }
            else if ($parameter->isPassedByReference()) {
                $ctx->add_defined_variable($var, [$parameter->getType()]);
            }
        }
    }

    end:
    foreach ($node->children as $key => $child) {
        if ($child instanceof \ast\Node &&
            !in_array($child->kind, [\ast\AST_CLASS, \ast\AST_FUNC_DECL]))
        {
            find_defined_variables($ctx, $child);
        }
    }
}

function is_statement_writable(\ast\Node $node): bool
{
    if ($node->kind === \ast\AST_ARRAY) {
        if (count($node->children) === 0) {
            return false;
        }
        foreach ($node->children as $child) {
            if (!($child->children['value'] instanceof \ast\Node) ||
                !is_statement_writable($child->children['value']))
            {
                return false;
            }
        }
    }
    return in_array($node->kind, [\ast\AST_VAR, \ast\AST_ARRAY, \ast\AST_ARRAY_ELEM, \ast\AST_PROP,
        \ast\AST_DIM, \ast\AST_REF, \ast\AST_STATIC_PROP]);
}

function validate_ast_node(ASTContext $ctx, \ast\Node $node): ?ASTContext
{
    if ($node->kind === \ast\AST_BINARY_OP && ($node->flags === \ast\flags\BINARY_IS_IDENTICAL ||
        $node->flags === \ast\flags\BINARY_IS_NOT_IDENTICAL))
    {
        $left_types = get_possible_types($ctx, $node->children['left']);
        if ($left_types === [null] || $left_types === []) {
            return $ctx;
        }
        $right_types = get_possible_types($ctx, $node->children['right']);
        if ($right_types === [null] || $right_types === []) {
            return $ctx;
        }
        $left_types = explode('|', type_to_string($left_types));
        $right_types = explode('|', type_to_string($right_types));
        if (count(array_intersect($left_types, $right_types)) !== 0 ||
            in_array('mixed', $left_types) || in_array('mixed', $right_types) ||
            in_array('bool', $left_types) &&
            (in_array('false', $right_types) || in_array('true', $right_types)) ||
            in_array('bool', $right_types) &&
            (in_array('false', $left_types) || in_array('true', $left_types)))
        {
            return $ctx;
        }
        $left_types = implode('|', $left_types);
        $right_types = implode('|', $right_types);
        $always_never = $node->flags === \ast\flags\BINARY_IS_IDENTICAL ? 'never' : 'always';
        $ctx->error("Condition is $always_never fulfilled because of the type mismatch between " .
            "`$left_types` and `$right_types`", $node);
    }
    else if ($node->kind === \ast\AST_RETURN) {
        if ($ctx->function === null || $ctx->function->isGenerator()) {
            # `function` can be null because PHP supports `return` in the global scope
            return $ctx;
        }
        $return_type_hint = $ctx->function->getReturnType();
        if ($return_type_hint === null) {
            return $ctx;
        }
        $ctx->has_return = true;
        if ($node->children['expr'] === null) {
            $returned_type = ['void'];
        }
        else {
            $returned_type = get_possible_types($ctx, $node->children['expr']);
        }
        if (!type_has_supertype($ctx, $returned_type, [$return_type_hint])) {
            $returned_type_str = type_to_string($returned_type);
            $return_type_hint_str = type_to_string([$return_type_hint]);
            $ctx->error("Returned type `$returned_type_str` is incompatible with the return type hint " .
                "`$return_type_hint_str`", $node);
        }
    }
    else if ($node->kind === \ast\AST_FOREACH) {
        validate_ast_children($ctx, $node->children['expr']);
        if (!is_statement_writable($node->children['value'])) {
            $ctx->error("The value of the `foreach` loop is not writable", $node);
            return null;
        }
        if ($node->children['key'] !== null) {
            if (!is_statement_writable($node->children['key'])) {
                $ctx->error("The key of the `foreach` loop is not writable", $node);
                return null;
            }
            validate_ast_children($ctx, $node->children['key']);
        }
        $ctx->is_in_assignment = true;
        validate_ast_children($ctx, $node->children['value']);
        $ctx->is_in_assignment = false;
        validate_ast_children($ctx, $node->children['stmts']);
        return null;
    }
    else if ($node->kind === \ast\AST_ASSIGN || $node->kind === \ast\AST_ASSIGN_OP) {
        if (!is_statement_writable($node->children['var'])) {
            $ctx->error("The left-hand site of the assignment is not writable", $node);
            return null;
        }
        if ($node->children['expr'] instanceof \ast\Node) {
            validate_ast_children($ctx, $node->children['expr']);
        }
        $ctx->is_in_assignment = true;
        validate_ast_children($ctx, $node->children['var']);
        $ctx->is_in_assignment = false;
        $possible_types = get_possible_types($ctx, $node->children['var'], false, true);
        $expr_types = get_possible_types($ctx, $node->children['expr']);
        if (!type_has_supertype($ctx, $expr_types, $possible_types)) {
            $possible_types_str = type_to_string($possible_types);
            $expr_types_str = type_to_string($expr_types);
            $ctx->error("Cannot assign type `$expr_types_str` to variable of type " .
                "`$possible_types_str`", $node);
        }
        return null;
    }
    else if ($node->kind === \ast\AST_CATCH) {
        $possible_types = [];
        foreach ($node->children['class']->children as $class_node) {
            $class = $ctx->fq_class_name($class_node);
            if (!$ctx->class_exists($class) && !interface_exists($class)) {
                $ctx->error("Undefined class `$class`", $node->children['class']);
                continue;
            }
            $possible_types []= $class;
        }
        if ($node->children['var'] !== null) {
            $var = $node->children['var']->children['name'];
            $ctx->defined_variables[$var] = new DefinedVariable($var, $possible_types);
        }
    }
    else if ($node->kind === \ast\AST_METHOD_CALL || $node->kind === \ast\AST_STATIC_CALL) {
        $possible_methods = get_possible_methods($ctx, $node, true);
        if ($possible_methods === []) {
            $ctx->error("Undefined method `{$node->children['method']}`", $node);
        }
        if ($possible_methods === null || count($possible_methods) !== 1) {
            validate_arguments($ctx, null, $node->children['args']);
            return $ctx;
        }
        if ($node->kind === \ast\AST_STATIC_CALL) {
            $parent_class_names = [];
            if (($parent = $ctx->class) !== null) {
                while (($parent = $parent->getParentClass()) !== false) {
                    $parent_class_names []= strtolower($parent->getName());
                }
            }
            if (!in_array(strtolower($node->children['class']->children['name']),
                ['self', 'parent', ...$parent_class_names]) && !$possible_methods[0]->isStatic())
            {
                $ctx->error("Method `{$possible_methods[0]->getName()}` is not static", $node);
            }
        }
        validate_arguments($ctx, $possible_methods[0], $node->children['args']);
    }
    else if ($node->kind === \ast\AST_NEW) {
        $types = get_possible_types($ctx, $node, true);
        if (count($types) > 1 || $types[0] === null) {
            validate_arguments($ctx, null, $node->children['args']);
            return $ctx;
        }
        $class = $ctx->get_class($types[0]);
        if ($class === null) {
            $ctx->error("Undefined class `{$types[0]}`", $node);
            return $ctx;
        }
        if ($class->isAbstract()) {
            $ctx->error("Cannot instantiate abstract class `{$class->getName()}`", $node);
            return $ctx;
        }
        $constructor = $class->getConstructor();
        if ($constructor === null && count($node->children['args']->children) > 0) {
            $ctx->error("The constructor of class `{$class->getName()}` does not accept arguments", $node);
            return $ctx;
        }
        validate_arguments($ctx, $constructor, $node->children['args']);
    }
    else if ($node->kind === \ast\AST_CONST) {
        if (!$ctx->constant_exists_with_fallback($node->children['name'])) {
            $ctx->error("Undefined constant `{$node->children['name']->children['name']}`", $node);
        }
    }
    else if ($node->kind === \ast\AST_CALL) {
        $function = null;
        if ($node->children['expr']->kind === \ast\AST_NAME) {
            $function = $ctx->get_function($node->children['expr']);
            if ($function === null) {
                $ctx->error("Undefined function `{$node->children['expr']->children['name']}`", $node);
            }
        }
        validate_arguments($ctx, $function, $node->children['args']);
    }
    else if ($node->kind === \ast\AST_VAR) {
        $var = $node->children['name'];
        if (!($var instanceof \ast\Node) && !array_key_exists($var, $ctx->defined_variables)) {
            $ctx->error("Undefined variable `$$var`", $node);
        }
    }
    else if ($node->kind === \ast\AST_NAMESPACE) {
        if ($node->children['stmts'] !== null) {
            $ctx = clone $ctx;
        }
        $ctx->namespace = $node->children['name'] === null ? '' : $node->children['name'] . '\\';
        $ctx->use_aliases = [];
    }
    else if ($node->kind === \ast\AST_USE) {
        $ctx->fill_use_aliases($node);
    }
    else if ($node->kind === \ast\AST_CLASS) {
        $ctx = clone $ctx;
        $ctx->class = $ctx->get_class($ctx->namespace . $node->children['name']);
        if ($ctx->class === null) {
            return null;  # Skipping analysis of redefined class
        }
    }
    else if (in_array($node->kind, [\ast\AST_FUNC_DECL, \ast\AST_METHOD, \ast\AST_CLOSURE])) {
        $ctx2 = clone $ctx;
        $ctx2->has_return = false;
        $ctx2->reset_defined_variables();

        if ($node->kind !== \ast\AST_METHOD) {
            $ctx2->class = null;
        }
        if ($node->kind === \ast\AST_CLOSURE) {
            if (array_key_exists('this', $ctx->defined_variables)) {
                $ctx2->defined_variables['this'] = $ctx->defined_variables['this'];
            }
            foreach ($node->children['uses']->children ?? [] as $use) {
                $var = $use->children['name'];
                if (array_key_exists($var, $ctx->defined_variables)) {
                    $ctx2->add_defined_variable($var, $ctx->defined_variables[$var]->possible_types);
                    continue;
                }
                $ctx2->add_defined_variable($var, [null]);
                if ($use->flags !== \ast\flags\CLOSURE_USE_REF) {
                    $ctx->error("Undefined closure variable `$var`", $node);
                }
            }
            $ctx2->function = new AST_ReflectionFunction($ctx2, $node);
            $ctx2->function->initialize();
        }
        else if ($node->kind === \ast\AST_METHOD) {
            $ctx2->function = $ctx2->class->getMethod($node->children['name']);
            if (!$ctx2->function->isStatic()) {
                $ctx2->defined_variables['this'] = new DefinedVariable('this', [$ctx2->class->getName()]);
            }
        }
        else {
            // Names of function declarations are never fully qualified
            $function_name = $ctx2->namespace . $node->children['name'];
            $ctx2->function = $ctx2->defined_functions[strtolower($function_name)];
        }

        foreach ($ctx2->function->getParameters() as $p) {
            $ctx2->add_defined_variable($p->getName(), [$p->getType()]);
        }
        if ($node->children['stmts'] !== null) {
            find_defined_variables($ctx2, $node->children['stmts']);
        }
        return $ctx2;
    }
    else if ($node->kind === \ast\AST_ARROW_FUNC) {
        $ctx = clone $ctx;
        $ctx->function = new AST_ReflectionFunction($ctx, $node);
        $ctx->function->initialize();
        foreach ($ctx->function->getParameters() as $p) {
            $ctx->add_defined_variable($p->getName(), [$p->getType()]);
        }
    }
    return $ctx;
}

function validate_ast_children(ASTContext $ctx, \ast\Node $node, ?\ast\Node $parent_node=null): void
{
    $prop_kinds = [\ast\AST_CLASS_CONST, \ast\AST_STATIC_PROP, \ast\AST_PROP];
    if (in_array($node->kind, $prop_kinds) && !in_array($parent_node?->kind, $prop_kinds)) {
        get_possible_types($ctx, $node, true, $ctx->is_in_assignment);
    }
    if (($child_ctx = validate_ast_node($ctx, $node)) === null) {
        return;
    }
    $is_in_assignment = $child_ctx->is_in_assignment;
    if (in_array($node->kind, [\ast\AST_PROP, \ast\AST_VAR, \ast\AST_DIM])) {
        $child_ctx->is_in_assignment = false;
    }
    foreach ($node->children as $child) {
        if (!($child instanceof \ast\Node)) {
            continue;
        }
        validate_ast_children($child_ctx, $child, $node);
    }
    $child_ctx->is_in_assignment = $is_in_assignment;
    if (in_array($node->kind, [\ast\AST_FUNC_DECL, \ast\AST_METHOD, \ast\AST_CLOSURE]) &&
        !$child_ctx->has_return && $child_ctx->function->is_return_required())
    {
        $child_ctx->error(
            "Function `{$child_ctx->function->getName()}` has a non-void return type hint but lacks a " .
            "return statement", $node
        );
    }
    $ctx->has_error = $ctx->has_error || $child_ctx->has_error;
}

function get_string_constant_value(ASTContext $ctx, \ast\Node|string $node): ?string
{
    if (is_string($node)) {
        return $node;
    }
    if ($node->kind === \ast\AST_MAGIC_CONST) {
        if ($node->flags === \ast\flags\MAGIC_FILE) {
            return $ctx->file_name;
        }
        else if ($node->flags === \ast\flags\MAGIC_DIR) {
            return dirname($ctx->file_name);
        }
    }
    if ($node->kind === \ast\AST_BINARY_OP && ($node->flags & \ast\flags\BINARY_CONCAT) !== 0) {
        $left = get_string_constant_value($ctx, $node->children['left']);
        $right = get_string_constant_value($ctx, $node->children['right']);
        if ($left === null || $right === null) {
            return null;
        }
        return $left . $right;
    }
    return null;
}

function traverse_classes_functions(ASTContext $ctx, \ast\Node $node): void
{
    foreach ($node->children as $child) {
        if ($child->kind === \ast\AST_INCLUDE_OR_EVAL && $child->flags !== \ast\flags\EXEC_EVAL) {
            $include_path = get_string_constant_value($ctx, $child->children['expr']);
            if ($include_path === null) {
                $ctx->error("Unable to resolve the include path", $child);
                continue;
            }
            if (is_link($include_path)) {
                $include_path = readlink($include_path);
            }
            if (!file_exists($include_path)) {
                $ctx->error("Include path `$include_path` does not exist", $child);
                continue;
            }
            $old_file_name = $ctx->file_name;
            $old_namespace = $ctx->namespace;
            load_source_file($ctx, $include_path);
            $ctx->file_name = $old_file_name;
            $ctx->namespace = $old_namespace;
        }
        else if ($child->kind === \ast\AST_CONST_DECL) {
            foreach ($child->children as $const) {
                if (in_array(strtolower($const->children['name']), ['null', 'false', 'true'])) {
                    $ctx->error("Redeclaration of constant `{$const->children['name']}`", $const);
                    continue;
                }
                $name = strtolower($ctx->namespace) . $const->children['name'];
                if ((array_key_exists($name, $ctx->defined_constants) || defined($name)) &&
                    !$ctx->make_self_check)
                {
                    $ctx->error("Redeclaration of constant `$name`", $const);
                    continue;
                }
                $ctx->defined_constants[$name] = $const->children['value'];
            }
        }
        else if ($child->kind === \ast\AST_NAMESPACE) {
            $ctx->use_aliases = [];
            $ctx->namespace = $child->children['name'] === null ? '' : $child->children['name'] . '\\';
            if ($child->children['stmts'] !== null) {
                traverse_classes_functions($ctx, $child->children['stmts']);
            }
        }
        else if ($child->kind === \ast\AST_USE) {
            $ctx->fill_use_aliases($child);
        }
        else if ($child->kind === \ast\AST_CLASS) {
            $name = $ctx->namespace . $child->children['name'];
            if ($ctx->class_exists($name) && !$ctx->make_self_check) {
                $ctx->error("Redeclaration of class `$name`", $child);
                $ctx->defined_classes[strtolower($name)] = null;
            }
            $ctx->defined_classes[strtolower($name)] = new AST_ReflectionClass($ctx, $child);
            $ctx->defined_interface_names []= strtolower($name);
        }
        else if ($child->kind === \ast\AST_FUNC_DECL) {
            $name = $ctx->namespace . $child->children['name'];
            if ($ctx->function_exists($name) && !$ctx->make_self_check) {
                $ctx->error("Redeclaration of function `$name`", $child);
                continue;
            }
            $ctx->defined_functions[strtolower($name)] = new AST_ReflectionFunction($ctx, $child);
        }
    }
}

function load_source_file(ASTContext $ctx, string $file_name): void
{
    $ctx->file_name = realpath($file_name);
    if (array_key_exists($ctx->file_name, $ctx->included_files)) {
        return;
    }
    try {
        $node = \ast\parse_file($file_name, 100);
    }
    catch (\ParseError $e) {
        $file_name = $ctx->get_relative_file_name();
        $message = ucfirst($e->getMessage());
        print("$file_name line \e[1m{$e->getLine()}\e[0m:\n$message\n");
        $ctx->has_error = true;
        return;
    }
    $ctx->included_files[$ctx->file_name] = $node;
    $ctx->namespace = '';
    traverse_classes_functions($ctx, $node);
}

function main(array $argv): int
{
    if (count($argv) === 1) {
        print(<<<EOT
            Specify PHP files to check.
            Options:
            --ignore-file-prefix <prefix>
            \tIgnore files or directories with the specified prefix. Example value: `vendor/`.
            --eval <file>
            \tEvaluate the specified file before starting the analysis.
            \tIntended to support autoloading. Example value: `vendor/autoload.php`.
            --statistics
            \tPrint SLOC count and lists of the checked and of the ignored files.

            EOT
        );
        return 0;
    }
    $ignored_file_prefixes = [];
    $print_statistics = false;
    $ctx = new ASTContext;
    $ctx->make_self_check = in_array(__FILE__, array_map(realpath(...), array_slice($argv, 1)));
    for ($i = 1; $i < count($argv); ++$i) {
        if ($argv[$i] === '--ignore-file-prefix' && $i < count($argv) - 1) {
            $prefix = realpath('') . '/' . $argv[++$i];
            $prefix = preg_replace('|/(\./)+|', '/', $prefix);
            do {
                $prefix = preg_replace('|/[^/]*?/\.\.|', '', $prefix, -1, $count);
            } while ($count > 0);
            $ignored_file_prefixes []= $prefix;
            continue;
        }
        if ($argv[$i] === '--statistics') {
            $print_statistics = true;
            continue;
        }
        if ($argv[$i] === '--eval' && $i < count($argv) - 1) {
            require_once $argv[++$i];
            continue;
        }
        if (!is_file($argv[$i])) {
            print("The file `{$argv[$i]}` does not exist\n");
            return 1;
        }
        load_source_file($ctx, $argv[$i]);
    }
    if ($ctx->has_error) { # Omitting further validation in case of syntax errors
        return 1;
    }

    # Resolving type hints, traits, parent class/interfaces is only possible after having loaded all classes

    foreach ($ctx->defined_classes as $defined_class) {
        if ($defined_class !== null) {
            $defined_class->initialize();
        }
    }
    $ctx->class = null;
    foreach ($ctx->defined_functions as $defined_function) {
        $defined_function->initialize();
    }

    $sloc_count = 0;
    $checked_files = [];
    $ignored_files = [];
    foreach ($ctx->included_files as $file_name => $node) {
        if (!$ctx->make_self_check && $file_name === __FILE__) {
            continue;
        }
        $ignore = false;
        foreach ($ignored_file_prefixes as $ignored_file_prefix) {
            if (str_starts_with($file_name, $ignored_file_prefix)) {
                $ignored_files []= $file_name;
                $ignore = true;
                break;
            }
        }
        if ($ignore) {
            continue;
        }
        $checked_files []= $file_name;
        $code = file_get_contents($file_name);
        if ($print_statistics) {
            $sloc_count += substr_count($code, "\n");
        }
        $ctx->add_defined_variable('argc', ['int']);
        $ctx->add_defined_variable('argv', ['array']);
        $ctx->file_name = $file_name;
        $ctx->namespace = '';
        find_defined_variables($ctx, $node);
        validate_ast_children($ctx, $node);
    }
    if ($print_statistics) {
        $checked_files = count($checked_files) === 0 ? ' (none)' : "\n" . implode("\n", $checked_files);
        $ignored_files = count($ignored_files) === 0 ? ' (none)' : "\n" . implode("\n", $ignored_files);
        print("$sloc_count lines of code have been checked.\n");
        print("Checked files:$checked_files\n");
        print("Ignored files:$ignored_files\n");
    }

    return $ctx->has_error ? 1 : 0;
}

exit(main($argv));
