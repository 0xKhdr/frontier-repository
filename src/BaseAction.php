<?php

declare(strict_types=1);

namespace Frontier\Repositories;

use Frontier\Actions\BaseAction as FrontierBaseAction;
use Frontier\Repositories\Contracts\Repository;

/**
 * Base action class for repository operations.
 *
 * Provides integration between actions and repositories.
 */
class BaseAction extends FrontierBaseAction
{
    protected Repository $repository;
}
