<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;
use Illuminate\Support\Collection;

class RetrieveAction extends EloquentAction
{
    public function handle(array $columns = ['*'], array $options = []): Collection
    {
        return $this->model->query()->select($columns)->get();
    }
}
