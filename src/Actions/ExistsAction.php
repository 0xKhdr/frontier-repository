<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;

class ExistsAction extends BaseAction
{
    public function handle(array $conditions): bool
    {
        return $this->repository->exists($conditions);
    }
}
