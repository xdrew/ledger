<?php

declare(strict_types=1);

namespace App\Infrastructure\OpenApi;

use App\Infrastructure\Http\ApiKeyAuthenticator;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Builds an OpenAPI 3.1 document by reflecting over the invokable `Action`
 * controllers under a scan root (e.g. `src/Api`). Mirrors the travel project's
 * generator: routes, `#[MapRequestPayload]` request DTOs, `JsonSerializable`
 * response DTOs, path params, and the `#[OpenApiPublic]`/`#[ResponseStatus]`/
 * `#[Tag]`/`#[QueryParam]` attributes — with an API-key security scheme rather
 * than travel's bearer JWT. No third-party OpenAPI library is involved.
 */
final class OpenApiGenerator
{
    private const SECURITY_SCHEME = 'apiKey';

    /** @var array<string, array<string, mixed>> */
    private array $paths = [];

    /** @var array<string, mixed> */
    private array $schemas = [];

    /**
     * @return array<string, mixed>
     */
    public function generate(string $apiPath): array
    {
        $this->paths = [];
        $this->schemas = [];

        foreach ($this->actionClasses($apiPath) as $className) {
            $this->processActionClass($className);
        }

        ksort($this->paths);
        ksort($this->schemas);

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Ledger Core API',
                'version' => '1.0.0',
                'description' => 'Event-sourced payment ledger — HTTP API (generated from source).',
            ],
            'servers' => [],
            'paths' => $this->paths,
            'components' => [
                'schemas' => $this->schemas,
                'securitySchemes' => [
                    self::SECURITY_SCHEME => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => ApiKeyAuthenticator::HEADER,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<class-string>
     */
    private function actionClasses(string $apiPath): array
    {
        $finder = new Finder();
        $finder->files()->in($apiPath)->name('*Action.php');

        $classes = [];
        foreach ($finder as $file) {
            $className = $this->classNameFromFile($file->getRealPath());
            if ($className !== null && class_exists($className)) {
                $classes[] = $className;
            }
        }

        return $classes;
    }

    /**
     * @return class-string|null
     */
    private function classNameFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        if (preg_match('/namespace\s+([^;]+);/', $content, $ns) === 1
            && preg_match('/\bclass\s+(\w+)/', $content, $cls) === 1) {
            /** @var class-string $fqcn */
            $fqcn = $ns[1] . '\\' . $cls[1];

            return $fqcn;
        }

        return null;
    }

    /**
     * @param class-string $className
     */
    private function processActionClass(string $className): void
    {
        $reflection = new \ReflectionClass($className);
        if (!$reflection->hasMethod('__invoke')) {
            return;
        }

        $method = $reflection->getMethod('__invoke');
        foreach ($method->getAttributes(Route::class) as $routeAttribute) {
            $this->processRoute($routeAttribute->newInstance(), $method, $reflection);
        }
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function processRoute(Route $route, \ReflectionMethod $method, \ReflectionClass $class): void
    {
        $routePath = $route->path;
        if (!\is_string($routePath) || $routePath === '') {
            return;
        }

        $path = '/api' . $routePath;
        $methods = $route->methods !== [] ? $route->methods : ['GET'];
        $isPublic = $this->isPublic($method, $class);
        $successStatus = $this->successStatus($method);

        $operation = [
            'operationId' => $this->operationId($class->getName()),
            'tags' => [$this->tag($class)],
            'summary' => $this->summary($class->getName()),
            'responses' => $this->responses($method, $successStatus),
        ];

        $parameters = [...$this->pathParameters($path), ...$this->queryParameters($method)];
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $requestBody = $this->requestBody($method);
        if ($requestBody !== null) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => ['application/json' => ['schema' => $requestBody]],
            ];
        }

        if (!$isPublic) {
            $operation['security'] = [[self::SECURITY_SCHEME => []]];
        }

        foreach ($methods as $httpMethod) {
            $this->paths[$path][strtolower($httpMethod)] = $operation;
        }
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function isPublic(\ReflectionMethod $method, \ReflectionClass $class): bool
    {
        return $method->getAttributes(OpenApiPublic::class) !== []
            || $class->getAttributes(OpenApiPublic::class) !== [];
    }

    private function successStatus(\ReflectionMethod $method): int
    {
        $attributes = $method->getAttributes(ResponseStatus::class);

        return $attributes === [] ? 200 : $attributes[0]->newInstance()->code;
    }

    /**
     * @return list<array{name: string, in: string, required: bool, schema: array{type: string}}>
     */
    private function pathParameters(string $path): array
    {
        $parameters = [];
        if (preg_match_all('/\{(\w+)\}/', $path, $matches) > 0) {
            foreach ($matches[1] as $name) {
                if ($name === 'format') {
                    continue;
                }
                $parameters[] = [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ];
            }
        }

        return $parameters;
    }

    /**
     * @return list<array{name: string, in: string, required: bool, schema: array<string, mixed>, description: string}>
     */
    private function queryParameters(\ReflectionMethod $method): array
    {
        $parameters = [];
        foreach ($method->getAttributes(QueryParam::class) as $attribute) {
            $param = $attribute->newInstance();
            $schema = $this->mapType($param->type);
            if ($param->enum !== []) {
                $schema['enum'] = $param->enum;
            }
            $parameters[] = [
                'name' => $param->name,
                'in' => 'query',
                'required' => $param->required,
                'schema' => $schema,
                'description' => $param->description,
            ];
        }

        return $parameters;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(\ReflectionMethod $method): ?array
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getAttributes(MapRequestPayload::class) === []) {
                continue;
            }
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                return $this->schemaRef($type->getName());
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function responses(\ReflectionMethod $method, int $successStatus): array
    {
        if ($successStatus === 204) {
            return ['204' => ['description' => 'No Content']];
        }

        $responseClass = $this->jsonSerializableReturn($method->getReturnType());
        $schema = $responseClass !== null ? $this->schemaRef($responseClass) : ['type' => 'object'];

        return [
            (string) $successStatus => [
                'description' => 'Successful response',
                'content' => ['application/json' => ['schema' => $schema]],
            ],
        ];
    }

    private function jsonSerializableReturn(?\ReflectionType $returnType): ?string
    {
        $types = match (true) {
            $returnType instanceof \ReflectionNamedType => [$returnType],
            $returnType instanceof \ReflectionUnionType => $returnType->getTypes(),
            default => [],
        };

        foreach ($types as $type) {
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }
            $name = $type->getName();
            if (class_exists($name) && (new \ReflectionClass($name))->implementsInterface(\JsonSerializable::class)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Registers the schema for a request/response class (once) and returns a $ref.
     *
     * @return array{'$ref': string}
     */
    private function schemaRef(string $className): array
    {
        $name = $this->schemaName($className);
        if (!isset($this->schemas[$name]) && class_exists($className)) {
            $this->schemas[$name] = $this->buildSchema($className);
        }

        return ['$ref' => '#/components/schemas/' . $name];
    }

    /**
     * @param class-string $className
     * @return array<string, mixed>
     */
    private function buildSchema(string $className): array
    {
        $reflection = new \ReflectionClass($className);
        $properties = [];
        $required = [];

        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $param) {
                $properties[$param->getName()] = $this->parameterSchema($param);
                if (!$param->isOptional()) {
                    $required[] = $param->getName();
                }
            }
        }

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!isset($properties[$property->getName()])) {
                $properties[$property->getName()] = $this->namedTypeSchema($property->getType());
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterSchema(\ReflectionParameter $param): array
    {
        $schema = $this->namedTypeSchema($param->getType());
        foreach ($param->getAttributes() as $attribute) {
            if (str_contains($attribute->getName(), 'Assert\NotBlank')) {
                $schema['minLength'] = 1;
            }
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function namedTypeSchema(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $this->mapType($type->getName());
        }

        return ['type' => 'string'];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapType(string $phpType): array
    {
        return match ($phpType) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'object', 'additionalProperties' => true],
            default => ['type' => 'string'],
        };
    }

    private function operationId(string $className): string
    {
        $parts = [];
        foreach (explode('\\', $className) as $part) {
            if (!\in_array($part, ['App', 'Api', 'Action'], true)) {
                $parts[] = lcfirst($part);
            }
        }

        return implode('', $parts);
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function tag(\ReflectionClass $class): string
    {
        $attributes = $class->getAttributes(Tag::class);
        if ($attributes !== []) {
            return $attributes[0]->newInstance()->name;
        }

        foreach (explode('\\', $class->getName()) as $part) {
            if (!\in_array($part, ['App', 'Api', 'Action'], true)) {
                return $part;
            }
        }

        return 'Default';
    }

    private function summary(string $className): string
    {
        $parts = [];
        $afterApi = false;
        foreach (explode('\\', $className) as $part) {
            if ($part === 'Api') {
                $afterApi = true;

                continue;
            }
            if ($afterApi && $part !== 'Action') {
                $parts[] = $part;
            }
        }

        return implode(' ', $parts);
    }

    private function schemaName(string $className): string
    {
        $parts = explode('\\', $className);
        $name = array_pop($parts);

        $apiIndex = array_search('Api', $parts, true);
        if ($apiIndex !== false && ($name === 'Request' || $name === 'Response')) {
            $moduleParts = \array_slice($parts, $apiIndex + 1);
            if ($moduleParts !== []) {
                return implode('', $moduleParts) . $name;
            }
        }

        return $name;
    }
}
