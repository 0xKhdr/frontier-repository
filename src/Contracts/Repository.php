<?php

declare(strict_types=1);

namespace Frontier\Repositories\Contracts;

use Frontier\Repositories\Contracts\Concerns\Creatable;
use Frontier\Repositories\Contracts\Concerns\Deletable;
use Frontier\Repositories\Contracts\Concerns\Readable;
use Frontier\Repositories\Contracts\Concerns\RepositoryUtility;
use Frontier\Repositories\Contracts\Concerns\Updatable;

/**
 * Complete Repository Contract Interface.
 *
 * This interface combines all repository operation interfaces into a single contract.
 * It follows the Interface Segregation Principle (ISP) by extending smaller,
 * focused interfaces that can be used independently.
 *
 * ## Composed Interfaces
 *
 * - {@see Creatable} - CREATE operations (create, createMany, insert, firstOrCreate)
 * - {@see Readable} - READ operations (find, retrieve, paginate, count)
 * - {@see Updatable} - UPDATE operations (update, updateBy, updateById, upsert)
 * - {@see Deletable} - DELETE operations (delete, deleteBy, deleteById)
 * - {@see RepositoryUtility} - Utility operations (chunk, transaction, builder access)
 *
 * ## Usage
 *
 * You can type-hint against this interface when you need full repository functionality:
 *
 * ```php
 * public function __construct(Repository $repository) {}
 * ```
 *
 * Or use specific interfaces when you only need certain operations:
 *
 * ```php
 * use Frontier\Repositories\Contracts\Concerns\Readable;
 * use Frontier\Repositories\Contracts\Concerns\Creatable;
 *
 * public function __construct(Readable $repository) {}  // Only read operations
 * public function __construct(Creatable $repository) {} // Only create operations
 * ```
 *
 * Or combine specific interfaces:
 *
 * ```php
 * use Frontier\Repositories\Contracts\Concerns\Readable;
 * use Frontier\Repositories\Contracts\Concerns\Updatable;
 *
 * public function handle(Readable&Updatable $repository) {}
 * ```
 *
 * ## Implementations
 *
 * @see \Frontier\Repositories\BaseRepository Default implementation
 * @see \Frontier\Repositories\BaseRepositoryCache Cached decorator implementation
 */
interface Repository extends Creatable, Deletable, Readable, RepositoryUtility, Updatable
{
    // This interface combines all repository operations.
    // See individual Concerns interfaces for method documentation.
}
