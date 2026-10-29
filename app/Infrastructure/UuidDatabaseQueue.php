<?php

namespace App\Infrastructure;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Str;

class UuidDatabaseQueue extends DatabaseQueue
{
    protected function buildDatabaseRecord($queue, $payload, $availableAt, $attempts = 0)
    {
        return ['id' => (string) Str::uuid7(), ...parent::buildDatabaseRecord($queue, $payload, $availableAt, $attempts)];
    }

    protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0)
    {
        $record = $this->buildDatabaseRecord($this->getQueue($queue), $payload, $this->availableAt($delay), $attempts);
        $this->database->table($this->table)->insert($record);

        return $record['id'];
    }
}
