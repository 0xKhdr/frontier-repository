<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;

class CountAction extends BaseAction
{
    public function handle(array $conditions): int
    {
        return $this->repository->count($conditions);
    }
}
