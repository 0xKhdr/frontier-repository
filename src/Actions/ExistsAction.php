<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;

class ExistsAction extends AbstractRepositoryAction
{
    public function handle(array $conditions): bool
    {
        return $this->repository->exists($conditions);
    }
}
