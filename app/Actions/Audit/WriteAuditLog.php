<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class WriteAuditLog
{
    public function handle(User $actor, string $action, Model $entity, array $before, array $after, ?string $reason = null): AuditLog
    {
        $audit = new AuditLog;
        $audit->actor_id = $actor->getKey();
        $audit->action = $action;
        $audit->entity_type = $entity->getMorphClass();
        $audit->entity_id = $entity->getKey();
        $audit->before = $before;
        $audit->after = $after;
        $audit->reason = $reason;
        $audit->save();

        return $audit;
    }
}
