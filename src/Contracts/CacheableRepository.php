<?php

declare(strict_types=1);

namespace Frontier\Repositories\Contracts;

/**
 * Contract for cacheable repository implementations.
 */
interface CacheableRepository
{
    /**
     * Get the cache TTL in seconds.
     */
    public function getCacheTtl(): int;

    /**
     * Get the cache key prefix.
     */
    public function getCachePrefix(): string;

    /**
     * Get the cache driver name.
     */
    public function getCacheDriver(): ?string;

    /**
     * Determine if caching is enabled.
     */
    public function shouldCache(): bool;

    /**
     * Clear all cache for this repository.
     */
    public function clearCache(): bool;

    /**
     * Disable caching for the next query.
     */
    public function withoutCache(): static;

    /**
     * Force refresh the cache on next query.
     */
    public function refreshCache(): static;
}
