<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;
use Illuminate\Database\Eloquent\Model;

class UpdateOrCreateAction extends EloquentAction
{
    public function handle(array $conditions, array $values): Model
    {
        return $this->model->where($conditions)->updateOrCreate($values);
    }
}