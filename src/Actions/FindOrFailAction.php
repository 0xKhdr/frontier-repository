<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\RepositoryAction;
use Illuminate\Database\Eloquent\Model;

class FindOrFailAction extends RepositoryAction
{
    public function handle(array $conditions, array $columns = ['*'], array $with = []): Model
    {
        return $this->repository->findOrFail($conditions, $columns, $with);
    }
}
