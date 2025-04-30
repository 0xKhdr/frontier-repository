<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\RepositoryAction;

class ExistsAction extends RepositoryAction
{
    public function handle(array $conditions): bool
    {
        return $this->repository->exists($conditions);
    }
}
