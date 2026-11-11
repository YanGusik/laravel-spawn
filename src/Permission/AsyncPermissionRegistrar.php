<?php

namespace Spawn\Laravel\Permission;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\PermissionRegistrar;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * Coroutine-safe PermissionRegistrar.
 *
 * Before bootCompleted(): behaves like stock PermissionRegistrar.
 * After bootCompleted(): per-request mutable state is stored in RequestContext.
 *
 * Isolated state:
 * - team ID (setPermissionsTeamId / getPermissionsTeamId)
 * - wildcard permissions index (getWildcardPermissionIndex / forgetWildcardPermissionIndex)
 * - clearPermissionsCollection() becomes a no-op in async mode
 *   (shared $permissions cache is read-only after load, safe to share)
 */
class AsyncPermissionRegistrar extends PermissionRegistrar
{
    private const CTX_TEAM_ID = 'permission.team_id';
    private const CTX_WILDCARD_INDEX = 'permission.wildcard_index';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        if (! $this->async) {
            parent::setPermissionsTeamId($id);
            return;
        }

        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        // Wrapped, so that a request's explicit null is told apart from a request that set none.
        RequestContext::current()->set(self::CTX_TEAM_ID, ['id' => $id], replace: true);
    }

    public function getPermissionsTeamId(): int|string|null
    {
        if (! $this->async) {
            return parent::getPermissionsTeamId();
        }

        $set = RequestContext::current()->find(self::CTX_TEAM_ID);

        // A request that set no team reads the resolver: the team set at boot, or whatever a
        // custom permission.team_resolver answers.
        return $set === null ? parent::getPermissionsTeamId() : $set['id'];
    }

    public function getWildcardPermissionIndex(Model $record): array
    {
        if (! $this->async) {
            return parent::getWildcardPermissionIndex($record);
        }

        $ctx = RequestContext::current();
        $index = $ctx->find(self::CTX_WILDCARD_INDEX) ?? [];

        $key = $record::class . ':' . $record->getKey();

        if (isset($index[$key])) {
            return $index[$key];
        }

        $result = app($record->getWildcardClass(), ['record' => $record])->getIndex();
        $index[$key] = $result;
        $ctx->set(self::CTX_WILDCARD_INDEX, $index, replace: true);

        return $result;
    }

    public function forgetWildcardPermissionIndex(?Model $record = null): void
    {
        if (! $this->async) {
            parent::forgetWildcardPermissionIndex($record);
            return;
        }

        $ctx = RequestContext::current();

        if ($record === null) {
            $ctx->set(self::CTX_WILDCARD_INDEX, [], replace: true);
            return;
        }

        $index = $ctx->find(self::CTX_WILDCARD_INDEX) ?? [];
        unset($index[$record::class . ':' . $record->getKey()]);
        $ctx->set(self::CTX_WILDCARD_INDEX, $index, replace: true);
    }

    /**
     * In async mode this is a no-op. The shared $permissions collection is
     * read-only after initial load and safe to share across coroutines.
     * Octane's per-request reset is unnecessary — team ID and wildcard
     * index are already isolated via RequestContext.
     */
    public function clearPermissionsCollection(): void
    {
        if (! $this->async) {
            parent::clearPermissionsCollection();
        }
    }
}
