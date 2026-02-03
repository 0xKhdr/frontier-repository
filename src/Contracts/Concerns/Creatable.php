<?php

declare(strict_types=1);

namespace Frontier\Repositories\Contracts\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Contract for CREATE operations.
 *
 * Defines methods for creating new records in the database.
 *
 * ## Model Lifecycle
 *
 * - `create()`, `createMany()`, `firstOrCreate()` - Uses Eloquent model (triggers casts, mutators, events)
 * - `insert()`, `insertGetId()` - Direct query builder (bypasses model lifecycle, faster)
 *
 * ## Performance Comparison
 *
 * | Method | Queries | Model Lifecycle | Use Case |
 * |--------|---------|-----------------|----------|
 * | create() | 1 | Yes | Single record with lifecycle |
 * | createMany() | N | Yes | Multiple records with lifecycle |
 * | insert() | 1 | No | Bulk insert, performance critical |
 * | insertGetId() | 1 | No | Bulk insert with ID return |
 * | firstOrCreate() | 1-2 | Yes | Idempotent creation |
 */
interface Creatable
{
    /*
    |--------------------------------------------------------------------------
    | Single Record Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new record using Eloquent model.
     *
     * This method uses Eloquent's model-level create, ensuring that:
     * - Attribute casting is applied
     * - Mutators are triggered
     * - Model events (creating/created) are fired
     * - Timestamps are automatically managed
     *
     * @param  array<string, mixed>  $values  The attributes to create
     * @return Model The newly created model instance
     *
     * @example
     * ```php
     * $user = $repository->create([
     *     'name' => 'John Doe',
     *     'email' => 'john@example.com',
     * ]);
     * ```
     */
    public function create(array $values): Model;

    /**
     * Find an existing record or create a new one.
     *
     * First attempts to find a record matching the conditions.
     * If not found, creates a new record with conditions + values merged.
     * Uses Eloquent model, so lifecycle events are triggered on create.
     *
     * This is an idempotent operation - calling it multiple times with
     * the same conditions will return the same record.
     *
     * @param  array<string, mixed>  $conditions  Attributes to search for
     * @param  array<string, mixed>  $values  Additional values for creation only
     * @return Model The found or newly created model
     *
     * @example
     * ```php
     * $user = $repository->firstOrCreate(
     *     ['email' => 'john@example.com'],           // Search criteria
     *     ['name' => 'John Doe', 'role' => 'user']   // Only used if creating
     * );
     * ```
     */
    public function firstOrCreate(array $conditions, array $values = []): Model;

    /*
    |--------------------------------------------------------------------------
    | Multiple Records Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Create multiple records using Eloquent models.
     *
     * This method creates each record using Eloquent's model-level create,
     * ensuring that for each record:
     * - Attribute casting is applied
     * - Mutators are triggered
     * - Model events (creating/created) are fired
     * - Timestamps are automatically managed
     *
     * Performance: Slower than insert() due to N queries (1 per record)
     * Use insert() for bulk operations where lifecycle isn't needed.
     *
     * @param  array<int, array<string, mixed>>  $records  Array of records to create
     * @return Collection<int, Model> Collection of created models
     *
     * @example
     * ```php
     * $users = $repository->createMany([
     *     ['name' => 'User 1', 'email' => 'user1@example.com'],
     *     ['name' => 'User 2', 'email' => 'user2@example.com'],
     * ]);
     * // Each model's "created" event was fired
     * ```
     */
    public function createMany(array $records): Collection;

    /*
    |--------------------------------------------------------------------------
    | Bulk Insert Operations (Bypass Model Lifecycle)
    |--------------------------------------------------------------------------
    */

    /**
     * Insert multiple records directly (bypasses model lifecycle).
     *
     * This method uses the query builder's insert, which:
     * - Does NOT trigger model events
     * - Does NOT apply casts or mutators
     * - Does NOT manage timestamps automatically
     * - Is significantly faster for bulk operations
     *
     * Use createMany() if you need model lifecycle features.
     *
     * @param  array<int|string, mixed>  $values  Records to insert
     * @return bool True if insertion was successful
     *
     * @example
     * ```php
     * $repository->insert([
     *     ['name' => 'User 1', 'email' => 'user1@example.com', 'created_at' => now()],
     *     ['name' => 'User 2', 'email' => 'user2@example.com', 'created_at' => now()],
     * ]);
     * ```
     */
    public function insert(array $values): bool;

    /**
     * Insert a record and return the new ID.
     *
     * Similar to insert() but returns the auto-increment ID.
     * Bypasses model lifecycle for performance.
     *
     * Note: Only works for single record insertion.
     *
     * @param  array<string, mixed>  $values  Values to insert
     * @return int The auto-increment ID of the inserted record
     *
     * @example
     * ```php
     * $id = $repository->insertGetId([
     *     'name' => 'John Doe',
     *     'email' => 'john@example.com',
     *     'created_at' => now(),
     * ]);
     * ```
     */
    public function insertGetId(array $values): int;
}
