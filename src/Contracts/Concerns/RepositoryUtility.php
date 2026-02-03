<?php

declare(strict_types=1);

namespace Frontier\Repositories\Contracts\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Contract for repository utility operations.
 *
 * Defines helper methods for advanced operations and access to underlying components.
 *
 * ## Methods
 *
 * ### Processing
 * - `chunk()` - Process large datasets in memory-efficient chunks
 * - `transaction()` - Execute operations within a database transaction
 *
 * ### Builder Management
 * - `withBuilder()` - Set a base query builder (for scopes/filters)
 * - `resetBuilder()` - Reset to fresh query state
 *
 * ### Component Access
 * - `getModel()` - Access the underlying Eloquent model
 * - `getTable()` - Get the database table name
 * - `getBuilder()` - Access the current query builder
 */
interface RepositoryUtility
{
    /**
     * Process records in chunks to conserve memory.
     *
     * Useful for processing large datasets without loading all records into memory.
     * The callback receives a Collection of models for each chunk.
     *
     * @param  int  $count  Number of records per chunk
     * @param  callable  $callback  Callback receiving Collection for each chunk
     * @return bool False if callback returns false (stops processing), true otherwise
     *
     * @example
     * ```php
     * $repository->chunk(100, function (Collection $users) {
     *     foreach ($users as $user) {
     *         // Process user
     *     }
     *     // Return false to stop processing
     * });
     * ```
     */
    public function chunk(int $count, callable $callback): bool;

    /**
     * Execute operations within a database transaction.
     *
     * All operations within the callback are wrapped in a transaction.
     * If any exception is thrown, the transaction is rolled back.
     *
     * @param  callable  $callback  Operations to execute
     * @return mixed The callback's return value
     *
     * @throws Throwable Re-throws any exception after rollback
     *
     * @example
     * ```php
     * $result = $repository->transaction(function () use ($repository) {
     *     $user = $repository->create(['name' => 'John']);
     *     $repository->updateById($user->id, ['status' => 'active']);
     *     return $user;
     * });
     * ```
     */
    public function transaction(callable $callback): mixed;

    /**
     * Set a base query builder for subsequent operations.
     *
     * Useful for applying global scopes or filters to all queries.
     *
     * @param  Builder  $builder  The query builder to use as base
     * @return static Returns self for method chaining
     *
     * @example
     * ```php
     * $repository->withBuilder(
     *     User::query()->where('tenant_id', $tenantId)
     * );
     * ```
     */
    public function withBuilder(Builder $builder): static;

    /**
     * Reset the query builder to a fresh state.
     *
     * Clears any applied conditions and resets to the base builder.
     *
     * @return static Returns self for method chaining
     */
    public function resetBuilder(): static;

    /**
     * Get the model's database table name.
     *
     * @return string The table name
     */
    public function getTable(): string;

    /**
     * Get the underlying Eloquent model instance.
     *
     * @return Model The model instance
     */
    public function getModel(): Model;

    /**
     * Get the current query builder instance.
     *
     * @return Builder The query builder
     */
    public function getBuilder(): Builder;
}
