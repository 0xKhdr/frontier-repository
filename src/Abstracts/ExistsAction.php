<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;

class ExistsAction extends EloquentAction
{
    public function handle(array $conditions): bool
    {
        return $this->model->where($conditions)->exists();
    }
}