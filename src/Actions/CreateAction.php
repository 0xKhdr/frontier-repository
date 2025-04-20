<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;
use Illuminate\Database\Eloquent\Model;

class CreateAction extends AbstractRepositoryAction
{
    public function handle(array $values): Model
    {
        return $this->repository->create($values);
    }
}
