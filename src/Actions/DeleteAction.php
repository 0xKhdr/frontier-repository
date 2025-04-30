<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\RepositoryAction;

class DeleteAction extends RepositoryAction
{
    public function handle(array $conditions): bool
    {
        return $this->repository->delete($conditions);
    }
}
