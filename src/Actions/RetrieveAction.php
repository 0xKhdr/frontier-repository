<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;
use Illuminate\Support\Collection;

class RetrieveAction extends AbstractRepositoryAction
{
    public function handle(array $columns = ['*'], array $options = []): Collection
    {
        return $this->repository->retrieve($columns, $options);
    }
}
