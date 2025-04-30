<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\RepositoryAction;

class UpdateAction extends RepositoryAction
{
    public function handle(array $conditions, array $values): int
    {
        return $this->repository->update($conditions, $values);
    }
}
