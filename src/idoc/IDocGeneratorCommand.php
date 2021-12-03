<?php

namespace OVAC\IDoc;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mpociot\Reflection\DocBlock;
use OVAC\IDoc\Tools\RouteMatcher;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Yaml\Yaml;

/**
 * This custom generator will parse and generate a beautiful
 * interractive documentation with openAPI schema.
 */
class IDocGeneratorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'idoc:generate
                            {--force : Force rewriting of existing routes}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate interractive api documentation.';

    private $routeMatcher;

    public function __construct(RouteMatcher $routeMatcher)
    {
        parent::__construct();
        $this->routeMatcher = $routeMatcher;
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $usingDingoRouter = strtolower(config('idoc.router')) == 'dingo';
        if ($usingDingoRouter) {
            $routes = $this->routeMatcher->getDingoRoutesToBeDocumented(config('idoc.routes'));
        } else {
            $routes = $this->routeMatcher->getLaravelRoutesToBeDocumented(config('idoc.routes'));
        }

        $generator = new IDocGenerator();

        $parsedRoutes = $this->processRoutes($generator, $routes);

        $parsedRoutes = collect($parsedRoutes)->groupBy('group');

        $this->writeMarkdown($parsedRoutes);
    }

    /**
     * @param Collection $parsedRoutes
     *
     * @return void
     */
    private function writeMarkdown($parsedRoutes)
    {
        $outputPath = public_path(config('idoc.output'));

        if (!File::isDirectory($outputPath)) {
            File::makeDirectory($outputPath, 0777, true, true);
        }

        $this->info('Generating OPEN API 3.0.0 Config');
        file_put_contents($outputPath . DIRECTORY_SEPARATOR . 'openapi.json', $this->generateOpenApi3Config($parsedRoutes));
    }

    /**
     * @param IDocGenerator $generator
     * @param array $routes
     *
     * @return array
     */
    private function processRoutes(IDocGenerator $generator, array $routes)
    {
        $parsedRoutes = [];

        foreach ($routes as $routeItem) {
            $route = $routeItem['route'];

            if ($this->isValidRoute($route) && $this->isRouteVisibleForDocumentation($route->getAction()['uses'])) {
                $parsedRoute = $generator->processRoute($route, $routeItem['apply']);

                if ($parsedRoute) array_push($parsedRoutes, $parsedRoute);

                $this->info(
                    'Processed route: [' .
                    implode(',', $generator->getMethods($route)) . '] ' .
                    $generator->getUri($route)
                );
            } else {
                $this->warn('Skipping route: [' . implode(',', $generator->getMethods($route)) . '] ' . $generator->getUri($route));
            }
        }

        return $parsedRoutes;
    }

    /**
     * @param $route
     *
     * @return bool
     */
    private function isValidRoute(Route $route)
    {
        return !is_callable($route->getAction()['uses']) && !is_null($route->getAction()['uses']);
    }

    /**
     * @param $route
     *
     * @return bool
     * @throws ReflectionException
     *
     */
    private function isRouteVisibleForDocumentation($route)
    {
        list($class, $method) = explode('@', $route);

        $reflection = new ReflectionClass($class);

        if (!$reflection->hasMethod($method)) {
            return false;
        }

        $comment = $reflection->getMethod($method)->getDocComment();

        if ($comment) {
            $phpdoc = new DocBlock($comment);

            return collect($phpdoc->getTags())
                ->filter(function($tag) use ($route) {
                    return $tag->getName() === 'hideFromAPIDocumentation';
                })
                ->isEmpty();
        }

        return true;
    }

    /**
     * Generate Open API 3.0.0 collection json file.
     *
     * @param Collection $routes
     *
     * @return string
     */
    private function generateOpenApi3Config(Collection $routes)
    {
        $result = $routes->map(function($routeGroup, $groupName) use ($routes) {

            return collect($routeGroup)->map(function($route) use ($groupName, $routes, $routeGroup) {

                $methodGroup = $routeGroup
                    ->where('uri', $route['uri'])
                    ->mapWithKeys(function($route) use ($groupName, $routes) {

                        $parameters = collect($route['parameters'])
                            ->map(function($schema, $name) use ($route) {
                                return [
                                    'in' => $schema['in'] ?? 'body',
                                    'name' => $name,
                                    'description' => $schema['description'],
                                    'required' => $schema['required'],
                                    'schema' => [
                                        'type' => $schema['type'],
                                        'example' => $schema['value'],
                                    ]
                                ];
                            });

                        $requestBody = empty($route['bodyParameters']) ? null : [
                            'required' => true,
                            'description' => '',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => $route['bodyParameters']
                                    ]
                                ]
                            ]
                        ];

                        return [
                            strtolower($route['methods'][0]) => [
                                "tags" => [$groupName],
                                'operationId' => $route['title'],
                                'description' => $route['description'],
                                'parameters' => $parameters->values()->toArray(),
                                'requestBody' => $requestBody,
                                'responses' => $route['responses'],
                                'x-code-samples' => collect(config('idoc.language-tabs'))
                                    ->map(fn($name, $lang) => [
                                        'lang' => $name,
                                        'source' => view('idoc::languages.' . $lang, compact('route'))->render(),
                                    ])->values()->toArray(),
                            ]
                        ];
                    });

                return collect([
                    ('/' . $route['uri']) => $methodGroup,
                ]);
            });
        });

        $paths = [];

        foreach ($result->filter()->toArray() as $groupName => $group) {
            foreach ($group as $key => $value) {
                $paths[key($value)] = $value[key($value)];
            }
        }

        $baseSchema = [];

        if (!empty(config('idoc.base_schema'))) {
            if (Str::endsWith(config('idoc.base_schema'), ['yml', 'yaml'])) {
                $baseSchema = Yaml::parse(file_get_contents(config('idoc.base_schema')));
            } else {
                $baseSchema = json_decode(file_get_contents(config('idoc.base_schema')), true);
            }
        }

        $collection = [

            'openapi' => '3.0.0',

            'info' => [
                'title' => config('idoc.title'),
                'version' => config('idoc.version'),
                'description' => config('idoc.description'),
                'termsOfService' => config('idoc.terms_of_service'),
                "license" => !empty(config('idoc.license')) ? config('idoc.license') : null,
                "contact" => config('idoc.contact'),
                "x-logo" => [
                    "url" => config('idoc.logo'),
                    "altText" => config('idoc.title'),
                    "backgroundColor" => config('idoc.color'),
                ],
            ],

            'components' => [

                'securitySchemes' => config('idoc.security'),

                'schemas' => $routes->mapWithKeys(function($routeGroup, $groupName) {

                    if ($groupName != 'Payment processors') {
                        return [];
                    }

                    return collect($routeGroup)->mapWithKeys(function($route) use ($groupName, $routeGroup) {

                        $bodyParameters = collect($route['bodyParameters'])->map(function($schema, $name) {

                            $type = $schema['type'];

                            if ($type === 'float') {
                                $type = 'number';
                            }

                            if ($type === 'json') {
                                $type = 'object';
                            }

                            return [
                                'in' => 'formData',
                                'name' => $name,
                                'description' => $schema['description'],
                                'required' => $schema['required'],
                                'type' => $type,
                                'default' => $schema['value'],
                            ];
                        });

                        return ["PM{$route['paymentMethod']->id}" => ['type' => 'object']

                            + (
                            count($required = $bodyParameters
                                ->values()
                                ->where('required', true)
                                ->pluck('name'))
                                ? ['required' => $required]
                                : []
                            )

                            + (
                            count($properties = $bodyParameters
                                ->values()
                                ->filter()
                                ->mapWithKeys(function($parameter) {
                                    return [
                                        $parameter['name'] => [
                                            'type' => $parameter['type'],
                                            'example' => $parameter['default'],
                                            'description' => $parameter['description'],
                                        ],
                                    ];
                                }))
                                ? ['properties' => $properties]
                                : []
                            )

                            + (
                            count($properties = $bodyParameters
                                ->values()
                                ->filter()
                                ->mapWithKeys(function($parameter) {
                                    return [$parameter['name'] => $parameter['default']];
                                }))
                                ? ['example' => $properties]
                                : []
                            )
                        ];
                    });
                })->filter(),
            ],

            'servers' => config('idoc.servers'),

            'paths' => $paths,

            'x-tagGroups' => config('idoc.tag_groups'),
        ];

        return json_encode(array_merge_recursive($collection, $baseSchema));
    }
}
