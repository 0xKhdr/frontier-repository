<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;

class DeleteAction extends EloquentAction
{
    public function handle(array $conditions): int
    {
        return $this->model->where($conditions)->delete();
    }
}