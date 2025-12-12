<?php

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;
use Illuminate\Database\Eloquent\Model;

class UpdateOrCreateAction extends BaseAction
{
    public function handle(array $conditions, array $values): Model
    {
        return $this->repository->updateOrCreate($conditions, $values);
    }
}
