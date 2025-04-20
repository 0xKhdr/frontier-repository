<?php

namespace Frontier\Repositories;

use Frontier\Actions\AbstractAction as FrontierAbstractAction;
use Frontier\Repositories\Contracts\EloquentRepository;

class AbstractRepositoryAction extends FrontierAbstractAction
{
    protected EloquentRepository $repository;
}
