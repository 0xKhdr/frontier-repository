<?php

declare(strict_types=1);

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;
use Illuminate\Database\Eloquent\Model;

class FindOrFailAction extends BaseAction
{
    public function handle(array $conditions, array $columns = ['*']): Model
    {
        return $this->repository->findByOrFail($conditions, $columns);
    }
}
