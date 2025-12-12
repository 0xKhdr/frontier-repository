<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;

class UpdateAction extends BaseAction
{
    public function handle(array $conditions, array $values): int
    {
        return $this->repository->update($conditions, $values);
    }
}
