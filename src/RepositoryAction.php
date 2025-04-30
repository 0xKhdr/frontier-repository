<?php

namespace Frontier\Repositories;

use Frontier\Actions\AbstractAction as FrontierAbstractAction;
use Frontier\Repositories\Contracts\RepositoryEloquent;

class RepositoryAction extends FrontierAbstractAction
{
    protected RepositoryEloquent $repository;
}
