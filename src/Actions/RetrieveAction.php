<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class RetrieveAction extends BaseAction
{
    public function handle(array $columns = ['*'], array $options = []): Collection
    {
        return $this->repository->retrieve($columns, $options);
    }
}
