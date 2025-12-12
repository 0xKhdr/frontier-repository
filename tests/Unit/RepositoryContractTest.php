<?php

declare(strict_types=1);

use Frontier\Repositories\Contracts\Repository;

describe('Repository Contract', function (): void {
    it('exists', function (): void {
        expect(interface_exists(Repository::class))->toBeTrue();
    });

    it('has CRUD methods', function (): void {
        expect(method_exists(Repository::class, 'create'))->toBeTrue()
            ->and(method_exists(Repository::class, 'update'))->toBeTrue()
            ->and(method_exists(Repository::class, 'delete'))->toBeTrue()
            ->and(method_exists(Repository::class, 'find'))->toBeTrue();
    });

    it('has query methods', function (): void {
        expect(method_exists(Repository::class, 'retrieve'))->toBeTrue()
            ->and(method_exists(Repository::class, 'retrievePaginate'))->toBeTrue()
            ->and(method_exists(Repository::class, 'count'))->toBeTrue()
            ->and(method_exists(Repository::class, 'exists'))->toBeTrue();
    });
});
