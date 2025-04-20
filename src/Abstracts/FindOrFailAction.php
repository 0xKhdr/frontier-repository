<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;
use Illuminate\Database\Eloquent\Model;

class FindOrFailAction extends EloquentAction
{
    public function handle(array $conditions): Model
    {
        return $this->model->where($conditions)->firstOrFail();
    }
}