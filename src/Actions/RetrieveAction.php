<?php

declare(strict_types=1);

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;
use Illuminate\Support\Collection;

class RetrieveAction extends BaseAction
{
    public function handle(array $columns = ['*'], array $options = []): Collection
    {
        return $this->repository->get($columns, $options);
    }
}
