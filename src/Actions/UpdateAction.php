<?php

declare(strict_types=1);

namespace Frontier\Repositories\Actions;

use Frontier\Repositories\BaseAction;

class UpdateAction extends BaseAction
{
    public function handle(array $conditions, array $values): int
    {
        return $this->repository->update($conditions, $values);
    }
}
