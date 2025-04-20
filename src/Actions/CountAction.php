<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;

class CountAction extends AbstractRepositoryAction
{
    public function handle(array $conditions): int
    {
        return $this->repository->count($conditions);
    }
}
