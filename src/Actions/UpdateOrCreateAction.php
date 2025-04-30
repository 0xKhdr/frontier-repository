<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\RepositoryAction;
use Illuminate\Database\Eloquent\Model;

class UpdateOrCreateAction extends RepositoryAction
{
    public function handle(array $conditions, array $values): Model
    {
        return $this->repository->updateOrCreate($conditions, $values);
    }
}
