<?php

namespace Frontier\Actions\Abstracts;

use Frontier\Actions\EloquentAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RetrievePaginateAction extends EloquentAction
{
    public function handle(array $columns = ['*'], array $options = []): LengthAwarePaginator
    {
        return $this->model->query()->select($columns)->paginate();
    }
}
