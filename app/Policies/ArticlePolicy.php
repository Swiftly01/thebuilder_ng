<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Article;
use App\Models\User;

final class ArticlePolicy
{
    public function viewAny(User $u): bool
    {
        return $u->can('content.read');
    }

    public function create(User $u): bool
    {
        return $u->can('content.create');
    }

    public function view(User $u, Article $a): bool
    {
        return $u->can('content.read') && ($a->author_id === $u->id || $u->can('content.publish'));
    }

    public function update(User $u, Article $a): bool
    {
        return $u->can('content.edit') && ($u->can('content.publish') || ($a->author_id === $u->id && in_array($a->status, ['draft', 'changes', 'rejected'], true)));
    }

    public function transition(User $u, Article $a, string $status): bool
    {
        return in_array($status, ['review', 'draft'], true) ? $this->update($u, $a) : $u->can('content.publish');
    }

    public function delete(User $u, Article $a): bool
    {
        return $u->can('content.publish');
    }
}
