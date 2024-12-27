<?php declare(strict_types=1);

namespace Nuwave\Lighthouse\Bind;

use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use Illuminate\Contracts\Container\Container;
use Nuwave\Lighthouse\Bind\Validation\BindingExists;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgDirectiveForArray;
use Nuwave\Lighthouse\Support\Contracts\ArgManipulator;
use Nuwave\Lighthouse\Support\Contracts\ArgTransformerDirective;
use Nuwave\Lighthouse\Support\Contracts\ArgumentValidation;
use Nuwave\Lighthouse\Support\Contracts\InputFieldManipulator;

class BindDirective extends BaseDirective implements ArgumentValidation, ArgTransformerDirective, ArgDirectiveForArray, ArgManipulator, InputFieldManipulator
{
    /**
     * @var \Nuwave\Lighthouse\Bind\BindDefinition<object>|null
     */
    private ?BindDefinition $definition = null;
    private mixed $binding;

    public function __construct(
        private Container $container,
    ) {
        $this->binding = new PendingBinding();
    }

    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Automatically inject (model) instances directly into a resolver's arguments. For example, instead of
injecting a user's ID, you can inject the entire User model instance that matches the given ID.

This is a GraphQL analogue for Laravel's Route Binding.
"""
directive @bind(
    """
    Specify the class name of the binding to use. This can be either an Eloquent 
    model or callable class to bind any other instance than a model.
    """
    class: String!
    
    """
    Specify the column name of a unique identifier to use when binding Eloquent models.
    By default, "id" is used the the primary key column.
    """
    column: String! = "id"
    
    """
    Specify the relations to eager-load when binding Eloquent models.
    """
    with: [String!]! = []
    
    """
    Specify whether the binding should be considered required. When required, a validation error will be thrown for 
    the argument or any item in the argument (when the argument is an array) for which a binding instance could not 
    be resolved. The field resolver will not be invoked in this case. When optional, the argument value will resolve
    as null or, when the argument is an array, any item in the argument value will be filtered out of the collection.
    """
    required: Boolean! = true
) on ARGUMENT_DEFINITION | INPUT_FIELD_DEFINITION
GRAPHQL;
    }

    /**
     * @return \Nuwave\Lighthouse\Bind\BindDefinition<object>
     */
    private function bindDefinition(): BindDefinition
    {
        return $this->definition ??= new BindDefinition(
            $this->directiveArgValue('class'),
            $this->directiveArgValue('column', 'id'),
            $this->directiveArgValue('with', []),
            $this->directiveArgValue('required', true),
        );
    }

    public function manipulateArgDefinition(
        DocumentAST &$documentAST,
        InputValueDefinitionNode &$argDefinition,
        FieldDefinitionNode &$parentField,
        ObjectTypeDefinitionNode|InterfaceTypeDefinitionNode &$parentType,
    ): void {
        $this->bindDefinition()->validate($argDefinition, $parentField);
    }

    public function manipulateInputFieldDefinition(
        DocumentAST &$documentAST,
        InputValueDefinitionNode &$inputField,
        InputObjectTypeDefinitionNode &$parentInput,
    ): void {
        $this->bindDefinition()->validate($inputField, $parentInput);
    }

    public function rules(): array
    {
        return match ($this->bindDefinition()->required) {
            true => [new BindingExists($this)],
            false => [],
        };
    }

    public function messages(): array
    {
        return [];
    }

    public function attribute(): ?string
    {
        return null;
    }

    public function transform(mixed $argumentValue): mixed
    {
        // When validating required bindings, the \Nuwave\Lighthouse\Bind\Validation\BindingExists validation rule
        // should call transform() before it is called by the directive resolver. To avoid resolving the bindings
        // multiple times, we should remember the resolved binding and reuse it every time transform() is called.
        if (! $this->binding instanceof PendingBinding) {
            return $this->binding;
        }

        $definition = $this->bindDefinition();

        $bind = match ($definition->isModelBinding()) {
            true => $this->container->make(ModelBinding::class),
            false => $this->container->make($definition->class),
        };

        return $this->binding = $bind($argumentValue, $definition);
    }
}
