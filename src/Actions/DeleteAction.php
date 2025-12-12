<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;

class DeleteAction extends BaseAction
{
    public function handle(array $conditions): bool
    {
        return $this->repository->delete($conditions);
    }
}
