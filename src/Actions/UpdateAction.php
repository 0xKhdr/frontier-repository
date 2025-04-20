<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;

class UpdateAction extends AbstractRepositoryAction
{
    public function handle(array $conditions, array $values): int
    {
        return $this->repository->update($conditions, $values);
    }
}
