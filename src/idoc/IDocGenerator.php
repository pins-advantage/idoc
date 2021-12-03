<?php

namespace OVAC\IDoc;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

use Faker\Factory;
use Mpociot\Reflection\DocBlock;
use Mpociot\Reflection\DocBlock\Tag;
use OVAC\IDoc\Tools\Traits\ParamHelpers;
use OVAC\IDoc\Util\ArrayUtil;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;


class IDocGenerator
{
    use ParamHelpers;

    /**
     * @param Route $route
     *
     * @return mixed
     */
    public function getUri(Route $route)
    {
        return $route->uri();
    }

    /**
     * @param Route $route
     *
     * @return mixed
     */
    public function getMethods(Route $route)
    {
        return array_diff($route->methods(), ['HEAD']);
    }

    /**
     * @param \Illuminate\Routing\Route $route
     * @param array $apply Rules to apply when generating documentation for this route
     *
     * @return array
     */
    public function processRoute(Route $route, array $rulesToApply = [])
    {
        $routeAction = $route->getAction();
        list($class, $method) = explode('@', $routeAction['uses']);
        $controller = new ReflectionClass($class);
        $method = $controller->getMethod($method);

        $routeGroup = $this->getRouteGroup($controller, $method);
        $docBlock = $this->parseDocBlock($method);
        $tags = $docBlock->getTags();

        if (empty($tags)) return null;

        $methodArguments = $method->getParameters();

        $parameters = collect($route->parameterNames())
            ->mapWithKeys(function(string $name) use ($method, $methodArguments, $docBlock) {
                /* @var \ReflectionParameter $parameter */
                $parameter = collect($methodArguments)->first(fn(\ReflectionParameter $a) => $a->name === $name);

                if (!$parameter) {
                    throw new \Exception("parameter $name does not match method signature for {$method->getName()}");
                }

                $type = $this->normalizeParameterType($parameter->getType()->getName());

                return [$name => [
                    'in' => 'path',
                    'type' => $type,
                    'description' => '',
                    'required' => true,
                    'value' => $this->generateDummyValue($type)
                ]];
            })
            ->toArray();

        $bodyParameters = collect($method->getParameters())
            ->map(function(\ReflectionParameter $parameter) {
                try {
                    $class = new ReflectionClass($parameter->getType()->getName());

                    if ($class->isSubclassOf(Request::class)) {
                        return collect($class->getProperties())
                            ->filter(fn($property) => $property->getDeclaringClass()->getName() === $class->getName())
                            ->mapWithKeys(function(\ReflectionProperty $property) {
                                $type = $property->getType()?->getName();
                                return [$property->getName() => [
                                    'in' => 'body',
                                    'type' => $type,
                                    'description' => '',
                                    'required' => true,
                                    'value' => $this->generateDummyValue($type)
                                ]];
                            });
                    }
                } catch (\Exception) {
                    return false;
                }

                return false;
            })
            ->filter(fn($value) => !!$value)
            ->first();

        $responses = collect($tags)->filter(fn($tag) => $tag->getName() === 'return')->values()
            ->mapWithKeys(function(Tag $tag) {
                $type = trim($tag->getType(), '\/\\');

                preg_match('/(array|Collection)?<?([\w]*)>?(\[\])?/', $type, $matches);

                $isList = !empty($matches[1]) || isset($matches[3]);

                return [200 => [
                    'status' => '200',
                    'description' => $isList ? "Array of $matches[2]s" : $matches[2],
                    'content' => [
                        'application/json' => [
                            'schema' => $isList
                                ? [
                                    'type' => 'array',
                                    'items' => ['$ref' => "#/components/schemas/$matches[2]"]
                                ]
                                : [
                                    '$ref' => "#/components/schemas/$matches[2]"
                                ]
                        ]
                    ]
                ]];
            });

        $parsedRoute = [
            'id' => md5($this->getUri($route) . ':' . implode($this->getMethods($route))),
            'group' => $routeGroup,
            'title' => $method->getName(),
            'description' => $docBlock->getLongDescription()->getContents() ?? $docBlock->getShortDescription(),
            'methods' => $this->getMethods($route),
            'uri' => $this->getUri($route),
            'parameters' => $parameters,
            'bodyParameters' => $bodyParameters,
            'authenticated' => $authenticated = $this->getAuthStatusFromDocBlock($tags),
            'responses' => $responses,
            'showresponse' => !empty($responses),
        ];

        if (!$authenticated && array_key_exists('Authorization', ($rulesToApply['headers'] ?? []))) {
            unset($rulesToApply['headers']['Authorization']);
        }

        $parsedRoute['headers'] = $rulesToApply['headers'] ?? [];

        return $parsedRoute;
    }

    /**
     * @param array $tags
     *
     * @return array
     */
    protected function getBodyParametersFromDocBlock(array $tags)
    {
        $parameters = collect($tags)
            ->filter(function($tag) {
                return $tag instanceof Tag && $tag->getName() === 'bodyParam';
            })
            ->mapWithKeys(function($tag) {
                preg_match('/(.+?)\s+(.+?)\s+(required\s+)?(.*)/', $tag->getContent(), $content);

                if (empty($content)) {
                    // this means only name and type were supplied
                    list($name, $type) = preg_split('/\s+/', $tag->getContent());
                    $required = false;
                    $description = '';
                } else {
                    list($_, $name, $type, $required, $description) = $content;
                    $description = trim($description);
                    if ($description == 'required' && empty(trim($required))) {
                        $required = $description;
                        $description = '';
                    }
                    $required = trim($required) == 'required' ? true : false;
                }

                $type = $this->normalizeParameterType($type);

                list($description, $example, $properties) = $this->parseDescription($tag->getDescription(), $type);

                $ref = $properties['$ref'] ?? null;

                $value = is_null($example)
                    ? $this->generateDummyValue($type, $ref ? ['$ref' => $ref] : null)
                    : $example;

                return in_array($name, ['allOf'])
                    ? $properties
                    : [$name => compact('type', 'description', 'required', 'value', 'properties')];
            })->toArray();

        return $parameters;
    }

    /**
     * @param array $tags
     *
     * @return bool
     */
    protected function getAuthStatusFromDocBlock(array $tags)
    {
        $authTag = collect($tags)
            ->first(function($tag) {
                return $tag instanceof Tag && strtolower($tag->getName()) === 'authenticated';
            });

        return (bool)$authTag;
    }

    /**
     * @param array $tags
     *
     * @return array
     */
    protected function getResponseParametersFromDocBlock(array $tags)
    {
        $reponses = collect($tags)
            ->filter(function($tag) {
                return $tag instanceof Tag && $tag->getName() === 'response';
            })
            ->mapWithKeys(function($tag) {
                preg_match('/([\d]{3})?([\h\S]*)?\n?([\w\W]*)?/m', $tag->getContent(), $content);

                list($_, $status, $description, $content) = $content;
                $description = trim($description);

                if (!empty($content)) {
                    $content = $this->parseProperties($content);
                }

                return [$status => compact('status', 'description', 'content')];
            })->toArray();

        return $this->generateResponses($reponses);
    }


    /**
     * @param ReflectionClass $controller
     * @param ReflectionMethod $method
     *
     * @return string
     */
    protected function getRouteGroup(ReflectionClass $controller, ReflectionMethod $method)
    {
        // @group tag on the method overrides that on the controller
        $docBlockComment = $method->getDocComment();
        if ($docBlockComment) {
            $phpdoc = new DocBlock($docBlockComment);
            foreach ($phpdoc->getTags() as $tag) {
                if ($tag->getName() === 'group') {
                    return $tag->getContent();
                }
            }
        }

        $docBlockComment = $controller->getDocComment();
        if ($docBlockComment) {
            $phpdoc = new DocBlock($docBlockComment);
            foreach ($phpdoc->getTags() as $tag) {
                if ($tag->getName() === 'group') {
                    return $tag->getContent();
                }
            }
        }

        return 'general';
    }

    /**
     * @param array $tags
     *
     * @return array
     */
    protected function getQueryParametersFromDocBlock(array $tags)
    {
        $parameters = collect($tags)
            ->filter(function($tag) {
                return $tag instanceof Tag && $tag->getName() === 'queryParam';
            })
            ->mapWithKeys(function($tag) {
                preg_match('/(.+?)\s+(.+?)\s+(required\s+)?(.*)/', $tag->getContent(), $content);
                if (empty($content)) {
                    // this means only name and type were supplied
                    list($name, $type) = preg_split('/\s+/', $tag->getContent());
                    $required = false;
                    $description = '';
                } else {
                    list($_, $name, $type, $required, $description) = $content;
                    $description = trim($description);
                    if ($description == 'required' && empty(trim($required))) {
                        $required = $description;
                        $description = '';
                    }
                    $required = trim($required) == 'required' ? true : false;
                }

                $type = $this->normalizeParameterType($type);
                list($description, $example, $properties) = $this->parseDescription($description, $type);
                $value = is_null($example) ? $this->generateDummyValue($type) : $example;

                return [$name => compact('type', 'description', 'required', 'value', 'properties')];
            })->toArray();

        return $parameters;
    }

    /**
     * @param ReflectionMethod $method
     * @return DocBlock
     */
    protected function parseDocBlock(ReflectionMethod $method)
    {
        $comment = $method->getDocComment();
        $docBlock = new DocBlock($comment);
        return $docBlock;
    }

    private function normalizeParameterType($type)
    {
        $typeMap = [
            'int' => 'integer',
            'bool' => 'boolean',
            'double' => 'float',
            'ref' => 'schema',
        ];

        return $type ? ($typeMap[$type] ?? $type) : 'string';
    }

    private function generateDummyValue(?string $type, array $schema = null)
    {
        $faker = Factory::create();
        $fakes = [
            'integer' => function() {
                return rand(1, 20);
            },
            'number' => function() use ($faker) {
                return $faker->randomFloat();
            },
            'float' => function() use ($faker) {
                return $faker->randomFloat();
            },
            'boolean' => function() use ($faker) {
                return $faker->boolean();
            },
            'string' => function() use ($faker) {
                return $faker->asciify('************');
            },
            'array' => fn() => $schema ?? '[]',
            'object' => fn() => $schema ?? '{}',
            'date' => function() use ($faker) {
                return $faker->dateTimeThisMonth()->format(\DateTime::ISO8601);
            },
            'schema' => function() use ($schema) {
                return $schema[0] ?? null;
            },
        ];

        $fake = $fakes[$type] ?? $fakes['string'];

        return $fake();
    }

    private function recursivelyGenerateDummyValues($properties, &$result = [], $path = '')
    {
        if (is_array($properties)) {
            if (isset($properties['items']['properties'])) {
                foreach ($properties['items']['properties'] as $propertyKey => $propertyValue) {
                    $this->recursivelyGenerateDummyValues(
                        $propertyValue,
                        $result,
                        "$path.0.$propertyKey"
                    );
                }
            } elseif (isset($properties['properties'])) {
                ArrayUtil::set($result, $path, $this->recursivelyGenerateDummyValues(
                    $properties['properties'],
                    $result,
                    $path
                ));
            } elseif (isset($properties['type'])) {
                $example = isset($properties['example'])
                    ? $properties['example']
                    : $this->generateDummyValue($properties['type']);

                ArrayUtil::set($result, $path, $example);
            } else {
                foreach ($properties as $key => $property) {
                    $this->recursivelyGenerateDummyValues(
                        $property,
                        $result,
                        "$path.$key"
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Allows users to specify an example for the parameter by writing 'Example: the-example',
     * to be used in example requests and response calls.
     *
     * @param string $description
     * @param string $type The type of the parameter. Used to cast the example provided, if any.
     *
     * @return array The description and included example.
     */
    private function parseDescription(string $description, string $type)
    {
        $properties = $this->parseProperties($description);
        list($description, $example) = $this->parseExample($description, $type);

        return [$description, $example, $properties];
    }

    private function parseProperties(string|array $description)
    {
        if (preg_match('/\s*(properties:\s*(.*)[\w\W]*)/m', $description, $content)) {
            $yaml = $this->parseYaml(array_pop($content), 'properties');
            return $yaml;
        } else {
            return $description;
        }
    }

    private function parseExample(string $description, string $type)
    {
        $regex = $this->getExampleRegexForType($type);
        $example = null;

        if (preg_match($regex, $description, $content)) {
            $description = $content[1];

            // examples are parsed as strings by default, we need to cast them properly
            $example = isset($content[2])
                ? $this->castToType($content[2], $type)
                : null;
        }

        return [$description, $example];
    }

    private function getExampleRegexForType(string $type)
    {
        switch ($type) {
            case 'object':
                return '/(?:.*)\n(.*)(\s+Example:\s*({(.*)[\w\W]*}))?/';
            case 'array':
                return '/(?:.*)\n(.*)(\s+Example:\s*(\[(.*)[\w\W]*\]))?/';
            default:
                return '/(.*)\s+Example:\s*(.*)\s*/';
        }
    }

    private function parseYaml(string $yaml, string $key)
    {
        try {
            $yaml = Yaml::parse($yaml);
            return $yaml[$key] ?? $yaml;
        } catch (\Exception $e) {
            print_r("Error parsing properties yaml: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Cast a value from a string to a specified type.
     *
     * @param string $value
     * @param string $type
     *
     * @return mixed
     */
    private function castToType(string $value, string $type)
    {
        $casts = [
            'integer' => 'intval',
            'number' => 'floatval',
            'float' => 'floatval',
            'boolean' => 'boolval',
        ];

        // First, we handle booleans. We can't use a regular cast,
        //because PHP considers string 'false' as true.
        if ($value == 'false' && $type == 'boolean') {
            return false;
        }

        if (in_array($type, ['json', 'object', 'array'])) {
            return $value;
        }

        if (isset($casts[$type])) {
            return $casts[$type]($value);
        }

        return $value;
    }

    /**
     * Generate the openAPI response object for a route
     *
     * @param array $responseContent
     */
    private function generateResponses(array $responses)
    {
        return array_reduce($responses, function($result, $response) {
            $status = (int)(isset($response['status']) ? $response['status'] : 200);
            $description = isset($response['description']) ? $response['description'] : 'success';
            $contentBody = isset($response['content']) ? $response['content'] : [];

            switch ($status) {
                case 204:
                    $content = [];
                    break;
                default:
                    $example = $this->recursivelyGenerateDummyValues($contentBody);
                    $content = [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => $contentBody,
                                'example' => $example
                            ]
                        ]
                    ];
            }

            $result[$status] = [
                'description' => $description,
                'content' => $content
            ];

            return $result;
        }, []);
    }
}
