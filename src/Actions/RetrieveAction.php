<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class RetrieveAction extends AbstractRepositoryAction
{
    public function handle(array $columns = ['*'], array $options = []): Collection
    {
        $perPage = Arr::get($options, 'per_page');

        return $perPage
            ? $this->repository->retrievePaginate($columns, $options)
            : $this->repository->retrieve($columns, $options);
    }
}
