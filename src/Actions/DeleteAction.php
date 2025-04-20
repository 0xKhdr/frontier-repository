<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;

class DeleteAction extends AbstractRepositoryAction
{
    public function handle(array $conditions): bool
    {
        return $this->repository->delete($conditions);
    }
}
