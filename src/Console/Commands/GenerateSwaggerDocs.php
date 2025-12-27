<?php

namespace Foziluff\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Validation\Rules\Enum as EnumRule;
use UnitEnum;

class GenerateSwaggerDocs extends Command
{
    protected $signature = 'swagger';

    /**
     * @throws ReflectionException
     */
    public function handle(): void
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (
                !Str::startsWith($route->getActionName(), 'App\\Http\\Controllers') ||
                !in_array('api', $route->gatherMiddleware())
            ) {
                continue;
            }

            $uri = '/' . ltrim($route->uri(), '/');
            $methods = array_filter($route->methods(), fn($m) => $m !== 'HEAD');

            foreach ($methods as $httpMethod) {
                $schema = null;

                $action = $route->getActionName();
                [$controller, $method] = explode('@', $action);
                $formRequest = $this->getFormRequest($controller, $method);

                $pathItem = [
                    'tags' => [$this->humanReadableTag($controller)],
                    'summary' => $this->humanReadableSummary($controller, $method),
                    'parameters' => $this->extractPathParameters($uri),
                    'responses' => $this->extractResponseCodes($controller, $method),
                ];

                $originalMethod = strtolower($httpMethod);

                if (in_array($originalMethod, ['put', 'patch'])) {
                    $httpMethod = 'POST';
                }

                if ($formRequest) {
                    $requestBody = $this->requestBodyFromFormRequest($formRequest);

                    $contentTypes = array_keys($requestBody['content']);
                    $firstContentType = $contentTypes[0] ?? 'application/json';
                    $rawSchema = $requestBody['content'][$firstContentType]['schema'] ?? [];

                    if (strtoupper($originalMethod) === 'GET') {
                        foreach ($rawSchema['properties'] ?? [] as $name => $prop) {
                            $pathItem['parameters'][] = [
                                'name' => $name,
                                'in' => 'query',
                                'required' => in_array($name, $rawSchema['required'] ?? []),
                                'schema' => $prop,
                            ];
                        }
                    } else {
                        $schema = json_decode(json_encode($rawSchema), true);

                        if (in_array($originalMethod, ['put', 'patch'])) {
                            $schema['properties']['_method'] = [
                                'type' => 'string',
                                'enum' => [strtoupper($originalMethod)],
                            ];
                            $schema['required'][] = '_method';
                        }

                        $pathItem['requestBody'] = [
                            'content' => [
                                $firstContentType => [
                                    'schema' => $schema
                                ]
                            ]
                        ];
                    }
                } elseif (in_array($originalMethod, ['put', 'patch'])) {
                    $pathItem['requestBody'] = [
                        'content' => [
                            'multipart/form-data' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        '_method' => [
                                            'type' => 'string',
                                            'enum' => [strtoupper($originalMethod)],
                                        ]
                                    ],
                                    'required' => ['_method']
                                ]
                            ]
                        ]
                    ];
                }


                if ($this->requiresAuth($route)) {
                    $pathItem['security'] = [['bearerAuth' => []]];
                }

                $paths[$uri][strtolower($httpMethod)] = $pathItem;
            }


        }

        $yaml = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Documentation',
                'version' => '1.0.0',
            ],
            'servers' => [
                [
                    'url' => config('app.url'),
                    'description' => 'Base API URL',
                ],
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ],
                ],
            ],
        ];


        $outputPath = public_path('api-docs.yaml');

        $htmlOutputPath = public_path('docs.html');


        if (!is_dir(dirname($outputPath))) {
            mkdir(dirname($outputPath), 0777, true);
        }

        if (!is_dir(dirname($htmlOutputPath))) {
            mkdir(dirname($htmlOutputPath), 0777, true);
        }

        file_put_contents($outputPath, Yaml::dump($yaml, 20, 2));

        file_put_contents($htmlOutputPath, $this->getHtml());
        $this->info("Swagger YAML generated: " . config('app.url') . '/docs.html');
    }


    private function getHtml(): string
    {
        return <<<PHP
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Swagger UI</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist/swagger-ui.css" />
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist/swagger-ui-bundle.js"></script>
<script>
    SwaggerUIBundle({
        url: '/api-docs.yaml',
        dom_id: "#swagger-ui"
    });
</script>
</body>
</html>
PHP;
    }

    protected function humanReadableTag(string $controller): string
    {
        $name = str_replace('Controller', '', class_basename($controller));
        return Str::title(Str::snake($name, ' '));
    }

    protected function humanReadableSummary(string $controller, string $method): string
    {
        $actionMap = [
            'store' => 'create',
            'update' => 'update',
            'destroy' => 'delete',
            'index' => 'list of',
            'show' => 'get',
        ];

        $resource = Str::snake(str_replace('Controller', '', class_basename($controller)), ' ');

        if ($method === 'index') {
            if (str_ends_with($resource, 'y')) {
                $resource = substr($resource, 0, -1) . 'ies';
            } else $resource .= 's';
        }
        $action = $actionMap[$method] ?? $method;

        $actionValue = "$action $resource";

        if (empty($actionMap[$method])) {
            $actionValue = $action;
        }

        return $actionValue;
    }


    protected function extractPathParameters(string $uri): array
    {
        preg_match_all('/{(\w+)}/', $uri, $matches);
        return collect($matches[1])->map(function ($param) {
            return [
                'name' => $param,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer'],
            ];
        })->toArray();
    }

    protected function getFormRequest(string $controllerClass, string $method): ?string
    {
        if (!method_exists($controllerClass, $method)) return null;
        $reflection = new ReflectionMethod($controllerClass, $method);

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            if ($type && is_subclass_of($type->getName(), FormRequest::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * @throws ReflectionException
     */
    protected function requestBodyFromFormRequest(string $formRequestClass): array
    {
        $instance = (new ReflectionClass($formRequestClass))->newInstanceWithoutConstructor();

        if (method_exists($instance, 'setContainer')) {
            $instance->setContainer(app())->setRedirector(app('redirect'));
        }

        $rules = method_exists($instance, 'rules') ? $instance->rules() : [];

        $properties = [];
        $requiredFields = [];
        $hasFile = false;

        foreach ($rules as $field => $rule) {
//            $parsed = is_array($rule) ? array_filter($rule, fn($r) => is_string($r)) : explode('|', $rule);
            $parsed = is_array($rule)
                ? $rule
                : explode('|', $rule);

            $type = $this->guessType($parsed);

            if ($type === 'file') {
                $hasFile = true;
            }

            if (in_array('required', $parsed)) {
                $requiredFields[] = $field;
            }

            $prop = ['type' => $type];

            if (in_array('nullable', $parsed)) {
                $prop['nullable'] = true;
            }

            if ($enum = $this->extractEnum($parsed)) {
                $prop['enum'] = $enum;
            }
            if ($type === 'boolean') {
                $prop['enum'] = [0, 1];
            }

            foreach ($parsed as $rulePart) {
                if (!is_string($rulePart)) {
                    continue;
                }
                if (Str::startsWith($rulePart, 'min:')) {
                    $value = (int)Str::after($rulePart, 'min:');
                    if ($type === 'string') $prop['minLength'] = $value;
                    elseif (in_array($type, ['integer', 'number'])) $prop['minimum'] = $value;
                }

                if (Str::startsWith($rulePart, 'max:')) {
                    $value = (int)Str::after($rulePart, 'max:');
                    if ($type === 'string') $prop['maxLength'] = $value;
                    elseif (in_array($type, ['integer', 'number'])) $prop['maximum'] = $value;
                }

                if (Str::startsWith($rulePart, 'between:')) {
                    [$min, $max] = str($rulePart)->after('between:')->explode(',')->map(fn($v) => (int)$v);

                    if ($type === 'string') {
                        $prop['minLength'] = $min;
                        $prop['maxLength'] = $max;
                    } elseif (in_array($type, ['integer', 'number'])) {
                        $prop['minimum'] = $min;
                        $prop['maximum'] = $max;
                    }
                }
            }

            $this->addPropertyToNestedArray($properties, $field, $prop);
        }

        $contentType = $hasFile ? 'multipart/form-data' : 'application/json';

        $example = method_exists($instance, 'example') ? $instance->example() : null;

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'required' => $requiredFields,
        ];

        if (is_array($example)) {
            $schema['example'] = $example;
        }

        return [
            'content' => [
                $contentType => [
                    'schema' => $schema
                ]
            ]
        ];
    }

    public function addPropertyToNestedArray(&$properties, $field, $prop): void
    {
        if (Str::contains($field, '.*')) {
            $arrayName = Str::before($field, '.*');
            $nestedField = trim(Str::after($field, '.*'), '.');

            if (!isset($properties[$arrayName])) {
                $properties[$arrayName] = [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => []
                    ]
                ];
            }
            if ($nestedField == "") {
                $properties[$arrayName]['items']['type'] = $prop['type'];
                return;
            }
            $this->addPropertyToNestedArray($properties[$arrayName]['items']['properties'], $nestedField, $prop);
        } else {
            $properties[$field] = $prop;
        }
    }

    protected function processNestedField(string $field, array $prop, &$properties): void
    {
        $segments = explode('.*.', $field);
        $currentLevel = &$properties;

        foreach ($segments as $segment) {
            if (!isset($currentLevel[$segment])) {
                $currentLevel[$segment] = [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => []
                    ]
                ];
            }
            $currentLevel = &$currentLevel[$segment]['items']['properties'];
        }

        $currentLevel[$segments[count($segments) - 1]] = $prop;
    }


    protected function extractEnum(array $rules): ?array
    {
        foreach ($rules as $rule) {
            if (is_string($rule) && Str::startsWith($rule, 'in:')) {
                return explode(',', Str::after($rule, 'in:'));
            }

            if ($rule instanceof EnumRule) {

                $enumClass = (function () {

                    /** @var class-string<UnitEnum> $this->type */

                    return $this->type;

                })->bindTo($rule, $rule::class)();

                return array_map(
                    fn ($case) => $case->value ?? $case->name,
                    $enumClass::cases()
                );
            }
        }

        return null;
    }



    protected function guessType(array $rules): string
    {
        $rules = array_filter($rules, fn($r) => is_string($r));
        $rules = collect($rules)->map(fn($r) => Str::lower($r))->all();

        if ($this->containsMime($rules)) {
            return 'file';
        }

        if (in_array('file', $rules) || in_array('image', $rules)) {
            return 'file';
        }
        if (in_array('integer', $rules)) return 'integer';
        if (in_array('numeric', $rules)) return 'number';
        if (in_array('boolean', $rules)) return 'boolean';
        if (in_array('array', $rules)) return 'array';

        return 'string';
    }


    protected function containsMime(array $rules): bool
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }

            if (Str::startsWith($rule, 'mimes') || Str::startsWith($rule, 'mimetypes')) {
                return true;
            }
        }

        return false;
    }


    protected function defaultResponses(): array
    {
        return [
            '200' => ['description' => 'Success'],
            '403' => ['description' => 'Forbidden'],
            '422' => ['description' => 'Validation error'],
        ];
    }

    /**
     * @throws ReflectionException
     */
    protected function extractResponseCodes(string $controller, string $method): array
    {
        $file = (new ReflectionClass($controller))->getFileName();
        $lines = file($file);
        $refMethod = new ReflectionMethod($controller, $method);

        $start = $refMethod->getStartLine() - 1;
        $end = $refMethod->getEndLine() - 1;

        $codeLines = array_slice($lines, $start, $end - $start + 1);
        $code = implode('', $codeLines);

        preg_match_all('/response\\(\\)->json\\(.*?,\\s*(\\d{3})\\)/s', $code, $responseMatches);
        preg_match_all('/response\(\)->json\([^)]*\)/', $code, $jsonCalls);
        preg_match_all('/abort\((\d{3})\)/', $code, $abortMatches);

        $statusCodes = [];

        if (!empty($responseMatches[1])) {
            $statusCodes = array_merge($statusCodes, $responseMatches[1]);
        }

        if (Str::contains($code, 'OrFail')) {
            $statusCodes[] = '404';
        }


        if (count($jsonCalls[0]) > count($responseMatches[0])) {
            $statusCodes[] = '200';
        }

        if (!empty($abortMatches[1])) {
            $statusCodes = array_merge($statusCodes, $abortMatches[1]);
        }

        $usesFormRequest = $this->getFormRequest($controller, $method);
        if ($usesFormRequest) {
            $statusCodes[] = '422';
        }

        $codes = collect($statusCodes)
            ->filter()
            ->unique()
            ->mapWithKeys(function ($code) {
                return [
                    $code => [
                        'description' => "Response $code",
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                ]
                            ]
                        ]
                    ]
                ];
            })
            ->toArray();

        $example = $this->exampleFromFormRequest($controller, $method);
        if ($example) {
            $codes['1'] = [
                'description' => 'Example',
                'content' => [
                    'application/json' => [
                        'example' => $example,
                    ],
                ],
            ];
        }

        return count($codes) > 0 ? $codes : ['200' => ['description' => 'OK']];
    }

    /**
     * @throws ReflectionException
     */
    protected function exampleFromFormRequest(string $controllerClass, string $method): ?array
    {
        $formRequestClass = $this->getFormRequest($controllerClass, $method);

        if (!$formRequestClass) {
            return null;
        }

        $instance = (new ReflectionClass($formRequestClass))->newInstanceWithoutConstructor();

        if (method_exists($instance, 'example')) {
            return $instance->example();
        }

        return null;
    }


    protected function requiresAuth($route): bool
    {
        $middlewares = $route->gatherMiddleware();

        foreach ($middlewares as $middleware) {
            if (Str::contains($middleware, ['auth', 'auth:sanctum', 'auth:api', 'auth:jwt', 'optional.sanctum'])) {
                return true;
            }
        }

        return false;
    }
}
