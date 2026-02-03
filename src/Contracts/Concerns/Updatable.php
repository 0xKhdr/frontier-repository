<?php

declare(strict_types=1);

namespace Frontier\Repositories\Contracts\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Contract for UPDATE operations.
 *
 * Defines methods for updating existing records in the database.
 *
 * ## Model Lifecycle
 *
 * - `update()`, `updateOrFail()`, `upsert()` - Direct query builder (bypasses model lifecycle, faster)
 * - `updateBy()`, `updateByOrFail()` - Uses Eloquent models (triggers casts, mutators, events)
 * - `updateById()`, `updateByIdOrFail()`, `updateOrCreate()` - Uses Eloquent model (triggers casts, mutators, events)
 *
 * ## Performance Comparison
 *
 * | Method | Queries | Model Lifecycle | Use Case |
 * |--------|---------|-----------------|----------|
 * | update() | 1 | No | Bulk updates, performance critical |
 * | updateOrFail() | 1 | No | Bulk updates with validation |
 * | updateBy() | N+1 | Yes | When lifecycle matters for multiple records |
 * | updateByOrFail() | N+1 | Yes | When lifecycle matters + validation |
 * | updateById() | 2 | Yes | Single record with lifecycle |
 * | upsert() | 1 | No | Bulk insert-or-update |
 */
interface Updatable
{
    /**
     * Bulk update records matching conditions (bypasses model lifecycle).
     *
     * This method uses the query builder directly, which:
     * - Does NOT trigger model events (updating/updated)
     * - Does NOT apply casts or mutators
     * - Is the fastest option for bulk updates
     *
     * Use updateBy() or updateById() if you need model lifecycle features.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return int Number of affected rows
     *
     * @example
     * ```php
     * $affected = $repository->update(
     *     ['status' => 'pending'],
     *     ['status' => 'processed', 'processed_at' => now()]
     * );
     * ```
     */
    public function update(array $conditions, array $values): int;

    /**
     * Bulk update records matching conditions or throw if none found.
     *
     * Same as update() but throws ModelNotFoundException if no records match.
     * Bypasses model lifecycle for performance.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return int Number of affected rows (always >= 1)
     *
     * @throws ModelNotFoundException When no records match the conditions
     *
     * @example
     * ```php
     * $affected = $repository->updateOrFail(
     *     ['status' => 'pending'],
     *     ['status' => 'processed']
     * );
     * // Throws ModelNotFoundException if no records match
     * ```
     */
    public function updateOrFail(array $conditions, array $values): int;

    /**
     * Update records matching conditions using Eloquent models.
     *
     * This method retrieves all matching records and updates each using
     * Eloquent's model-level update, ensuring that:
     * - Attribute casting is applied
     * - Mutators are triggered
     * - Model events (updating/updated) are fired for each record
     *
     * Performance: Slower than update() due to N+1 queries (1 SELECT + N UPDATEs)
     * Use update() for bulk operations where lifecycle isn't needed.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return Collection<int, Model> Collection of updated models
     *
     * @example
     * ```php
     * $updated = $repository->updateBy(
     *     ['status' => 'pending'],
     *     ['status' => 'processed']
     * );
     * // Each model's "updated" event was fired
     * ```
     */
    public function updateBy(array $conditions, array $values): Collection;

    /**
     * Update records matching conditions using Eloquent models or throw if none found.
     *
     * Same as updateBy() but throws ModelNotFoundException if no records match.
     * Uses Eloquent models, so lifecycle events are triggered.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @param  array<string, mixed>  $values  Values to update
     * @return Collection<int, Model> Collection of updated models
     *
     * @throws ModelNotFoundException When no records match the conditions
     *
     * @example
     * ```php
     * $updated = $repository->updateByOrFail(
     *     ['user_id' => 123],
     *     ['status' => 'inactive']
     * );
     * // Throws ModelNotFoundException if no records match
     * ```
     */
    public function updateByOrFail(array $conditions, array $values): Collection;

    /**
     * Update a record by its primary key using Eloquent model.
     *
     * Finds the record and updates it using the model's update() method,
     * triggering all model lifecycle features.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<string, mixed>  $values  Values to update
     * @return Model|null The updated model or null if not found
     *
     * @example
     * ```php
     * $user = $repository->updateById(1, ['name' => 'New Name']);
     * if ($user === null) {
     *     // Record not found
     * }
     * ```
     */
    public function updateById(int|string $id, array $values): ?Model;

    /**
     * Update a record by its primary key or throw exception.
     *
     * Same as updateById() but throws ModelNotFoundException if not found.
     *
     * @param  int|string  $id  The primary key value
     * @param  array<string, mixed>  $values  Values to update
     * @return Model The updated model
     *
     * @throws ModelNotFoundException When no record matches the ID
     *
     * @example
     * ```php
     * $user = $repository->updateByIdOrFail(1, ['name' => 'New Name']);
     * // Throws ModelNotFoundException if ID 1 doesn't exist
     * ```
     */
    public function updateByIdOrFail(int|string $id, array $values): Model;

    /**
     * Update an existing record or create a new one.
     *
     * Attempts to find a record matching conditions and update it.
     * If not found, creates a new record with conditions + values merged.
     * Uses Eloquent model, so lifecycle events are triggered.
     *
     * @param  array<string, mixed>  $conditions  Attributes to search for
     * @param  array<string, mixed>  $values  Values to update or set on creation
     * @return Model The updated or newly created model
     *
     * @example
     * ```php
     * $user = $repository->updateOrCreate(
     *     ['email' => 'john@example.com'],
     *     ['name' => 'John Doe', 'last_login' => now()]
     * );
     * ```
     */
    public function updateOrCreate(array $conditions, array $values): Model;

    /**
     * Bulk insert or update records (upsert).
     *
     * Efficiently inserts new records or updates existing ones in a single query.
     * Bypasses model lifecycle for performance.
     *
     * @param  array<int, array<string, mixed>>  $values  Records to upsert
     * @param  array<int, string>  $uniqueBy  Columns that determine uniqueness
     * @param  array<int, string>|null  $update  Columns to update (null = all except uniqueBy)
     * @return int Number of affected rows
     *
     * @example
     * ```php
     * $repository->upsert(
     *     [
     *         ['email' => 'john@example.com', 'name' => 'John', 'visits' => 1],
     *         ['email' => 'jane@example.com', 'name' => 'Jane', 'visits' => 1],
     *     ],
     *     ['email'],           // Unique constraint
     *     ['name', 'visits']   // Columns to update if exists
     * );
     * ```
     */
    public function upsert(array $values, array $uniqueBy, ?array $update = null): int;
}
