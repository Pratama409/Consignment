<?php

namespace App\Infrastructure;

use Illuminate\Database\QueryException;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Str;

class UuidDatabaseSessionHandler extends DatabaseSessionHandler
{
    public function validateId($id): bool
    {
        return $this->getQuery()->where('session_key', $id)->exists();
    }

    public function read($sessionId): string|false
    {
        $session = (object) $this->getQuery()->where('session_key', $sessionId)->first();
        if ($this->expired($session)) {
            $this->exists = true;

            return '';
        }
        if (isset($session->payload)) {
            $this->exists = true;

            return base64_decode($session->payload);
        }

        return '';
    }

    protected function performInsert($sessionId, $payload)
    {
        try {
            return $this->getQuery()->insert(['id' => (string) Str::uuid7(), 'session_key' => $sessionId, ...$payload]);
        } catch (QueryException $error) {
            if (! $this->validateId($sessionId)) {
                throw $error;
            }

            return $this->performUpdate($sessionId, $payload);
        }
    }

    protected function performUpdate($sessionId, $payload)
    {
        return $this->getQuery()->where('session_key', $sessionId)->update($payload);
    }

    public function destroy($sessionId): bool
    {
        $this->getQuery()->where('session_key', $sessionId)->delete();

        return true;
    }
}
