<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\AbstractRepositoryAction;
use Illuminate\Database\Eloquent\Model;

class UpdateOrCreateAction extends AbstractRepositoryAction
{
    public function handle(array $conditions, array $values): Model
    {
        return $this->repository->updateOrCreate($conditions, $values);
    }
}
