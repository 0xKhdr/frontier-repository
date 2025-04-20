<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;

class CountAction extends EloquentAction
{
    public function handle(array $conditions): int
    {
        return $this->model->where($conditions)->count();
    }
}