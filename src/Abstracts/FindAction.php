<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;
use Illuminate\Database\Eloquent\Model;

class FindAction extends EloquentAction
{
    public function handle(array $conditions): ?Model
    {
        return $this->model->where($conditions)->first();
    }
}