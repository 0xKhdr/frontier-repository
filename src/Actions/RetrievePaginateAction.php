<?php

declare(strict_types=1);

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class RetrievePaginateAction extends BaseAction
{
    public function handle(
        array $columns = ['*'],
        array $options = [],
        ?int $perPage = null,
        ?int $page = null
    ): LengthAwarePaginator {
        return $this->repository->paginate($columns, $options, $perPage, $page);
    }
}
