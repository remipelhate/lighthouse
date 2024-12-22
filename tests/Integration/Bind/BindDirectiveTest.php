<?php declare(strict_types=1);

namespace Tests\Integration\Bind;

use Illuminate\Support\Collection;
use Nuwave\Lighthouse\Exceptions\DefinitionException;
use Nuwave\Lighthouse\Testing\MocksResolvers;
use Nuwave\Lighthouse\Testing\UsesTestSchema;
use Tests\DBTestCase;
use Tests\Utils\Models\User;
use Tests\Utils\Resolvers\SpyResolver;
use Throwable;

use function factory;

final class BindDirectiveTest extends DBTestCase
{
    use UsesTestSchema;
    use MocksResolvers;

    public function testSchemaValidationFailsWhenClassArgumentDefinedOnFieldArgumentIsNotAClass(): void
    {
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }

            type Query {
                user(user: ID! @bind(class: "NotAClass")): User! @mock
            }
            GRAPHQL;

        $makeRequest = fn () => $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            query {
                user(user: "1") {
                    id
                }
            }
            GRAPHQL);

        $this->assertThrows($makeRequest, fn (DefinitionException $exception): bool => (
            $this->assertExceptionMessageContains(
                ['@bind', 'class', 'argument `user`', 'field `user`', 'NotAClass'],
                $exception,
            )
        ));
    }

    public function testSchemaValidationFailsWhenClassArgumentDefinedOnInputFieldIsNotAClass(): void
    {
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }

            input RemoveUsersInput {
                users: [ID!]! @bind(class: "NotAClass")
            }

            type Mutation {
                removeUsers(input: RemoveUsersInput!): Boolean! @mock
            }
            GRAPHQL;

        $makeRequest = fn () => $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            mutation ($input: RemoveUsersInput!) {
                removeUsers(input: $input)
            }
            GRAPHQL,
            [
                'input' => [
                    'users' => ['1'],
                ],
            ],
        );

        $this->assertThrows($makeRequest, fn (DefinitionException $exception): bool => (
            $this->assertExceptionMessageContains(
                ['@bind', 'class', 'field `users`', 'input `RemoveUsersInput`', 'NotAClass'],
                $exception,
            )
        ));
    }

    public function testSchemaValidationFailsWhenClassArgumentDefinedOnFieldArgumentIsNotAModelOrCallableClass(): void
    {
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }

            type Query {
                user(user: ID! @bind(class: "stdClass")): User! @mock
            }
            GRAPHQL;

        $makeRequest = fn () => $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            query {
                user(user: "1") {
                    id
                }
            }
            GRAPHQL);

        $this->assertThrows($makeRequest, fn (DefinitionException $exception): bool => (
            $this->assertExceptionMessageContains(
                ['@bind', 'class', 'argument `user`', 'field `user`', 'stdClass'],
                $exception,
            )
        ));
    }

    public function testSchemaValidationFailsWhenClassArgumentDefinedOnInputFieldIsNotAModelOrCallableClass(): void
    {
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }

            input RemoveUsersInput {
                users: [ID!]! @bind(class: "stdClass")
            }

            type Mutation {
                removeUsers(input: RemoveUsersInput!): Boolean! @mock
            }
            GRAPHQL;

        $makeRequest = fn () => $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            mutation ($input: RemoveUsersInput!) {
                removeUsers(input: $input)
            }
            GRAPHQL,
            [
                'input' => [
                    'users' => ['1'],
                ]
            ],
        );

        $this->assertThrows($makeRequest, fn (DefinitionException $exception): bool => (
            $this->assertExceptionMessageContains(
                ['@bind', 'class', 'field `users`', 'input `RemoveUsersInput`', 'stdClass'],
                $exception,
            )
        ));
    }

    public function testModelBindingOnFieldArgument(): void
    {
        $user = factory(User::class)->create();
        $this->mockResolver(fn (mixed $root, array $args): User => $args['user']);
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }
            
            type Query {
                user(user: ID! @bind(class: "Tests\\Utils\\Models\\User")): User! @mock
            }
            GRAPHQL;

        $response = $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            query ($id: ID!) {
                user(user: $id) {
                    id
                }
            }
            GRAPHQL, ['id' => $user->getKey()]);

        $response->assertGraphQLErrorFree();
        $response->assertJson([
            'data' => [
                'user' => [
                    'id' => $user->getKey(),
                ],
            ],
        ]);
    }

    public function testOptionalModelBindingOnFieldArgument(): void
    {
        $this->mockResolver(fn (mixed $root, array $args) => $args['user']);
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }
            
            type Query {
                user(
                    user: ID! @bind(class: "Tests\\Utils\\Models\\User", optional: true)
                ): User @mock
            }
            GRAPHQL;

        $response = $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            query ($id: ID!) {
                user(user: $id) {
                    id
                }
            }
            GRAPHQL, ['id' => '1']);

        $response->assertGraphQLErrorFree();
        $response->assertJson([
            'data' => [
                'user' => null,
            ],
        ]);
    }

    public function testModelCollectionBindingOnFieldArgument(): void
    {
        $users = factory(User::class, 2)->create();
        $resolver = new SpyResolver(return: true);
        $this->mockResolver($resolver);
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }

            type Mutation {
                removeUsers(
                    users: [ID!]! @bind(class: "Tests\\Utils\\Models\\User")
                ): Boolean! @mock
            }
            
            type Query {
                ping: Boolean
            }
            GRAPHQL;

        $response = $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            mutation ($users: [ID!]!) {
                removeUsers(users: $users)
            }
            GRAPHQL,
            [
                'users' => $users->map(fn (User $user): int => $user->getKey()),
            ],
        );

        $response->assertGraphQLErrorFree();
        $response->assertJson([
            'data' => [
                'removeUsers' => true,
            ],
        ]);
        $resolver->assertArgs(function (array $args) use ($users): void {
            $this->assertArrayHasKey('users', $args);
            $this->assertCount($users->count(), $args['users']);
            $users->each(function (User $user, int $key) use ($args): void {
                $this->assertTrue($user->is($args['users'][$key]));
            });
        });
    }

    public function testOptionalModelCollectionBindingOnFieldArgument(): void
    {
        $resolver = new SpyResolver(return: true);
        $this->mockResolver($resolver);
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }

            type Mutation {
                removeUsers(
                    users: [ID!]! @bind(class: "Tests\\Utils\\Models\\User", optional: true)
                ): Boolean! @mock
            }
            
            type Query {
                ping: Boolean
            }
            GRAPHQL;

        $response = $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            mutation ($users: [ID!]!) {
                removeUsers(users: $users)
            }
            GRAPHQL,
            [
                'users' => ['1', '2'],
            ],
        );

        $response->assertGraphQLErrorFree();
        $response->assertJson([
            'data' => [
                'removeUsers' => true,
            ],
        ]);
        $resolver->assertArgs(function (array $args): void {
            $this->assertArrayHasKey('users', $args);
            $this->assertEmpty($args['users']);
        });
    }

    public function testModelBindingOnInputField(): void
    {
        $user = factory(User::class)->create();
        $this->mockResolver(fn (mixed $root, array $args): User => $args['input']['user']);
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }
            
            input UserInput {
                user: ID! @bind(class: "Tests\\Utils\\Models\\User")
            }
            
            type Query {
                user(input: UserInput!): User! @mock
            }
            GRAPHQL;

        $response = $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            query ($input: UserInput!) {
                user(input: $input) {
                    id
                }
            }
            GRAPHQL,
            [
                'input' => [
                    'user' => $user->getKey(),
                ],
            ],
        );

        $response->assertGraphQLErrorFree();
        $response->assertJson([
            'data' => [
                'user' => [
                    'id' => $user->getKey(),
                ],
            ],
        ]);
    }

    public function testOptionalModelBindingOnInputField(): void
    {
        $this->mockResolver(fn (mixed $root, array $args) => $args['input']['user']);
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }
            
            input UserInput {
                user: ID! @bind(class: "Tests\\Utils\\Models\\User", optional: true)
            }
            
            type Query {
                user(input: UserInput!): User @mock
            }
            GRAPHQL;

        $response = $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            query ($input: UserInput!) {
                user(input: $input) {
                    id
                }
            }
            GRAPHQL,
            [
                'input' => [
                    'user' => '1',
                ],
            ],
        );

        $response->assertGraphQLErrorFree();
        $response->assertJson([
            'data' => [
                'user' => null,
            ],
        ]);
    }

    public function testModelCollectionBindingOnInputField(): void
    {
        $users = factory(User::class, 2)->create();
        $resolver = new SpyResolver(return: true);
        $this->mockResolver($resolver);
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }

            input RemoveUsersInput {
                users: [ID!]! @bind(class: "Tests\\Utils\\Models\\User")
            }

            type Mutation {
                removeUsers(input: RemoveUsersInput!): Boolean! @mock
            }
            
            type Query {
                ping: Boolean
            }
            GRAPHQL;

        $response = $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            mutation ($input: RemoveUsersInput!) {
                removeUsers(input: $input)
            }
            GRAPHQL,
            [
                'input' => [
                    'users' => $users->map(fn (User $user): int => $user->getKey()),
                ],
            ],
        );

        $response->assertGraphQLErrorFree();
        $response->assertJson([
            'data' => [
                'removeUsers' => true,
            ],
        ]);
        $resolver->assertArgs(function (array $args) use ($users): void {
            $this->assertArrayHasKey('input', $args);
            $this->assertArrayHasKey('users', $args['input']);
            $this->assertCount($users->count(), $args['input']['users']);
            $users->each(function (User $user, int $key) use ($args): void {
                $this->assertTrue($user->is($args['input']['users'][$key]));
            });
        });
    }

    public function testOptionalModelCollectionBindingOnInputField(): void
    {
        $resolver = new SpyResolver(return: true);
        $this->mockResolver($resolver);
        $this->schema = /* @lang GraphQL */ <<<'GRAPHQL'
            type User {
                id: ID!
            }

            input RemoveUsersInput {
                users: [ID!]! @bind(class: "Tests\\Utils\\Models\\User", optional: true)
            }

            type Mutation {
                removeUsers(input: RemoveUsersInput!): Boolean! @mock
            }
            
            type Query {
                ping: Boolean
            }
            GRAPHQL;

        $response = $this->graphQL(/* @lang GraphQL */ <<<'GRAPHQL'
            mutation ($input: RemoveUsersInput!) {
                removeUsers(input: $input)
            }
            GRAPHQL,
            [
                'input' => [
                    'users' => ['1', '2'],
                ],
            ],
        );

        $response->assertGraphQLErrorFree();
        $response->assertJson([
            'data' => [
                'removeUsers' => true,
            ],
        ]);
        $resolver->assertArgs(function (array $args): void {
            $this->assertArrayHasKey('input', $args);
            $this->assertArrayHasKey('users', $args['input']);
            $this->assertEmpty($args['input']['users']);
        });
    }

    private function assertExceptionMessageContains(array $fragments, Throwable $exception): bool
    {
        return Collection::make($fragments)
            ->each(function (string $fragment) use ($exception): void {
                $this->assertStringContainsString($fragment, $exception->getMessage());
            })
            ->isNotEmpty();
    }
}
