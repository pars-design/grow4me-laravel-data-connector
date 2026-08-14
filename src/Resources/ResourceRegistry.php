<?php

namespace ParsDesign\Grow4MeDataConnector\Resources;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use ParsDesign\Grow4MeDataConnector\Contracts\ResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Contracts\ResourceDependencyResolver;

class ResourceRegistry
{
    /**
     * @var array<string, ResourceAdapter>|null
     */
    private ?array $resources = null;

    public function __construct(private readonly Container $container) {}

    public function get(string $key): ResourceAdapter
    {
        $resource = $this->all()[$key] ?? null;

        abort_unless($resource instanceof ResourceAdapter, 404, 'Sync resource not found.');

        return $resource;
    }

    /**
     * @return array<string, ResourceAdapter>
     */
    public function all(): array
    {
        if ($this->resources !== null) {
            return $this->resources;
        }

        $resources = [];

        foreach (config('grow4me-connector.resources', []) as $resourceDefinition) {
            $resource = is_array($resourceDefinition)
                ? new ConfiguredEloquentResourceAdapter($resourceDefinition, $this->container)
                : $this->container->make($resourceDefinition);

            if (! $resource instanceof ResourceAdapter) {
                throw new InvalidArgumentException(sprintf(
                    'Configured resource [%s] must implement [%s].',
                    is_string($resourceDefinition) ? $resourceDefinition : get_debug_type($resourceDefinition),
                    ResourceAdapter::class,
                ));
            }

            if (preg_match('/\A[a-z0-9][a-z0-9._-]*\z/D', $resource->key()) !== 1) {
                throw new InvalidArgumentException(
                    "Grow4Me resource key [{$resource->key()}] must be lowercase and URL-safe.",
                );
            }

            if (array_key_exists($resource->key(), $resources)) {
                throw new InvalidArgumentException("Duplicate Grow4Me resource key [{$resource->key()}].");
            }

            $resources[$resource->key()] = $resource;
        }

        return $this->resources = $resources;
    }

    /**
     * @return list<ResourceDependencyResolver>
     */
    public function dependencies(): array
    {
        return collect(config('grow4me-connector.dependencies', []))
            ->map(function (mixed $resolverClass): ResourceDependencyResolver {
                $resolver = $this->container->make($resolverClass);

                if (! $resolver instanceof ResourceDependencyResolver) {
                    throw new InvalidArgumentException(sprintf(
                        'Configured dependency resolver [%s] must implement [%s].',
                        is_string($resolverClass) ? $resolverClass : get_debug_type($resolverClass),
                        ResourceDependencyResolver::class,
                    ));
                }

                return $resolver;
            })
            ->values()
            ->all();
    }
}
