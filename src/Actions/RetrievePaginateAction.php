<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RetrievePaginateAction extends AbstractRepositoryAction
{
    public function handle(array $columns = ['*'], array $options = []): LengthAwarePaginator
    {
        return $this->repository->retrievePaginate($columns, $options);
    }
}
