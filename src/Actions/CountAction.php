<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\RepositoryAction;

class CountAction extends RepositoryAction
{
    public function handle(array $conditions): int
    {
        return $this->repository->count($conditions);
    }
}
