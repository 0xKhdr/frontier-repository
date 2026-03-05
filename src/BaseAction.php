<?php

declare(strict_types=1);

namespace Frontier\Repositories;

use Frontier\Actions\BaseAction as FrontierBaseAction;
use Frontier\Repositories\Contracts\Repository;

/**
 * Base action class for repository-backed operations.
 *
 * Provides typed access to a primary repository instance. Subclasses must
 * inject a concrete repository in their own constructor and assign it to
 * $this->repository before handle() is called.
 *
 * @example
 * ```php
 * class CreateUserAction extends BaseAction
 * {
 *     public function __construct(UserRepository $repository)
 *     {
 *         $this->repository = $repository;
 *     }
 *
 *     public function handle(array $data): User
 *     {
 *         return $this->repository()->create($data);
 *     }
 * }
 * ```
 *
 * For actions requiring multiple repositories, inject them directly in the
 * concrete class and omit this base class.
 */
class BaseAction extends FrontierBaseAction
{
    protected Repository $repository;

    /**
     * Get the repository instance.
     *
     * Provides a typed accessor so subclasses can call $this->repository()
     * without having to cast the property manually.
     */
    protected function repository(): Repository
    {
        return $this->repository;
    }
}
