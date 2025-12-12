<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;
use Illuminate\Database\Eloquent\Model;

class FindOrFailAction extends BaseAction
{
    public function handle(array $conditions, array $columns = ['*'], array $with = []): Model
    {
        return $this->repository->findOrFail($conditions, $columns, $with);
    }
}
