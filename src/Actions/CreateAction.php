<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;
use Illuminate\Database\Eloquent\Model;

class CreateAction extends BaseAction
{
    public function handle(array $values): Model
    {
        return $this->repository->create($values);
    }
}
