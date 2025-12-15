<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;
use Illuminate\Database\Eloquent\Model;

class FindAction extends BaseAction
{
    public function handle(array $conditions, array $columns = ['*']): ?Model
    {
        return $this->repository->find($conditions, $columns);
    }
}
