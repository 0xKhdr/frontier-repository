<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;

class UpdateAction extends EloquentAction
{
    public function handle(array $conditions, array $values): int
    {
        return $this->model->where($conditions)->update($values);
    }
}