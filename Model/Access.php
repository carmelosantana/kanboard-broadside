<?php

namespace Kanboard\Plugin\Broadside\Model;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

class Access extends Base
{
    public function canViewShare(array $artifact, string $token): bool
    {
        $stored = $artifact['share_token'] ?? null;

        return is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }

    public function can(int $userId, int $projectId, string $action): bool
    {
        $user = $this->userModel->getById($userId);
        if (empty($user) || (int) $user['is_active'] !== 1 || ! $this->projectModel->exists($projectId)) {
            return false;
        }

        $role = $user['role'] === Role::APP_ADMIN
            ? Role::PROJECT_MANAGER
            : $this->projectUserRoleModel->getUserRole($projectId, $userId);
        if ($this->role->isCustomProjectRole($role)) {
            $role = Role::PROJECT_MEMBER;
        }

        return match ($action) {
            'view', 'comment', 'submit' => in_array($role, [Role::PROJECT_VIEWER, Role::PROJECT_MEMBER, Role::PROJECT_MANAGER], true),
            'publish' => in_array($role, [Role::PROJECT_MEMBER, Role::PROJECT_MANAGER], true),
            'remove', 'share' => $role === Role::PROJECT_MANAGER,
            default => false,
        };
    }
}
