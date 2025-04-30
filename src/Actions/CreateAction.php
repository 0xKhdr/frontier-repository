<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\RepositoryAction;
use Illuminate\Database\Eloquent\Model;

class CreateAction extends RepositoryAction
{
    public function handle(array $values): Model
    {
        return $this->repository->create($values);
    }
}
