<?php

declare(strict_types=1);

namespace Frontier\Repositories\Contracts\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/**
 * Contract for DELETE operations.
 *
 * Defines methods for removing records from the database.
 *
 * ## Model Lifecycle
 *
 * - `delete()`, `deleteOrFail()` - Direct query builder (bypasses model lifecycle, faster)
 * - `deleteBy()`, `deleteByOrFail()` - Uses Eloquent models (triggers events, respects soft deletes)
 * - `deleteById()`, `deleteByIdOrFail()` - Uses Eloquent model (triggers events, respects soft deletes)
 *
 * ## Soft Deletes
 *
 * If your model uses the `SoftDeletes` trait:
 * - `delete()`, `deleteOrFail()` will perform a hard delete (permanently removes records)
 * - `deleteBy()`, `deleteByOrFail()`, `deleteById()`, `deleteByIdOrFail()` will soft delete (sets deleted_at)
 *
 * ## Performance Comparison
 *
 * | Method | Queries | Model Lifecycle | Use Case |
 * |--------|---------|-----------------|----------|
 * | delete() | 1 | No | Bulk deletes, performance critical |
 * | deleteOrFail() | 1 | No | Bulk deletes with validation |
 * | deleteBy() | N+1 | Yes | When lifecycle matters for multiple records |
 * | deleteById() | 2 | Yes | Single record with lifecycle |
 */
interface Deletable
{
    /**
     * Bulk delete records matching conditions (bypasses model lifecycle).
     *
     * This method uses the query builder directly, which:
     * - Does NOT trigger model events (deleting/deleted)
     * - Does NOT respect SoftDeletes trait (performs hard delete)
     * - Is the fastest option for bulk deletes
     *
     * Use deleteBy() or deleteById() if you need model lifecycle features or soft deletes.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return int Number of deleted rows
     *
     * @example
     * ```php
     * $deleted = $repository->delete(['status' => 'expired']);
     * ```
     */
    public function delete(array $conditions): int;

    /**
     * Bulk delete records matching conditions or throw if none found.
     *
     * Same as delete() but throws ModelNotFoundException if no records match.
     * Bypasses model lifecycle for performance.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return int Number of deleted rows (always >= 1)
     *
     * @throws ModelNotFoundException When no records match the conditions
     *
     * @example
     * ```php
     * $deleted = $repository->deleteOrFail(['status' => 'expired']);
     * // Throws ModelNotFoundException if no records match
     * ```
     */
    public function deleteOrFail(array $conditions): int;

    /**
     * Delete records matching conditions using Eloquent models.
     *
     * This method retrieves all matching records and deletes each using
     * Eloquent's model-level delete, ensuring that:
     * - Model events (deleting/deleted) are fired for each record
     * - Soft deletes are respected (if model uses SoftDeletes trait)
     *
     * Performance: Slower than delete() due to N+1 queries (1 SELECT + N DELETEs)
     * Use delete() for bulk operations where lifecycle isn't needed.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return Collection<int, \Illuminate\Database\Eloquent\Model> Collection of deleted models
     *
     * @example
     * ```php
     * $deleted = $repository->deleteBy(['status' => 'expired']);
     * // Each model's "deleted" event was fired
     * ```
     */
    public function deleteBy(array $conditions): Collection;

    /**
     * Delete records matching conditions using Eloquent models or throw if none found.
     *
     * Same as deleteBy() but throws ModelNotFoundException if no records match.
     * Uses Eloquent models, so lifecycle events are triggered.
     *
     * @param  array<string, mixed>  $conditions  Where conditions
     * @return Collection<int, \Illuminate\Database\Eloquent\Model> Collection of deleted models
     *
     * @throws ModelNotFoundException When no records match the conditions
     *
     * @example
     * ```php
     * $deleted = $repository->deleteByOrFail(['user_id' => 123]);
     * // Throws ModelNotFoundException if no records match
     * ```
     */
    public function deleteByOrFail(array $conditions): Collection;

    /**
     * Delete a record by its primary key using Eloquent model.
     *
     * Finds the record and deletes it using the model's delete() method,
     * triggering all model lifecycle features including:
     * - Model events (deleting/deleted)
     * - Soft deletes (if model uses SoftDeletes trait)
     *
     * @param  int|string  $id  The primary key value
     * @return bool True if deleted, false if not found
     *
     * @example
     * ```php
     * if ($repository->deleteById(1)) {
     *     // Successfully deleted
     * } else {
     *     // Record not found
     * }
     * ```
     */
    public function deleteById(int|string $id): bool;

    /**
     * Delete a record by its primary key or throw exception.
     *
     * Same as deleteById() but throws ModelNotFoundException if not found.
     *
     * @param  int|string  $id  The primary key value
     * @return bool Always returns true (throws if not found)
     *
     * @throws ModelNotFoundException When no record matches the ID
     *
     * @example
     * ```php
     * $repository->deleteByIdOrFail(1);
     * // Throws ModelNotFoundException if ID 1 doesn't exist
     * ```
     */
    public function deleteByIdOrFail(int|string $id): bool;
}
