<?php

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Broadside\Model\Access;
use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class AccessTest extends Base
{
    use BroadsideHarness;

    public function testProjectAccessMatrix(): void
    {
        $this->setUpBroadside();
        $userModel = $this->container['userModel'];
        $projectRoles = $this->container['projectUserRoleModel'];
        $this->users['app admin'] = (int) $userModel->create(['username' => 'site-admin', 'role' => Role::APP_ADMIN]);
        $this->users['inactive'] = (int) $userModel->create(['username' => 'inactive', 'is_active' => 0]);
        $projectRoles->addUser($this->project, $this->users['inactive'], Role::PROJECT_MANAGER);
        $this->users['custom'] = (int) $userModel->create(['username' => 'custom']);
        $this->container['projectRoleModel']->create($this->project, 'Contributor');
        $projectRoles->addUser($this->project, $this->users['custom'], 'Contributor');

        $viewer = ['view', 'comment', 'submit'];
        $member = [...$viewer, 'publish'];
        $manager = [...$member, 'remove', 'share'];
        $allowedActions = [
            'viewer' => $viewer,
            'member' => $member,
            'manager' => $manager,
            'app admin' => $manager,
            'outsider' => [],
            'inactive' => [],
            'custom' => $member,
        ];

        $access = new Access($this->container);
        $missingProject = $this->otherProject + 1;
        foreach ($allowedActions as $name => $allowed) {
            foreach ($manager as $action) {
                $userId = $this->users[$name];
                $this->assertSame(in_array($action, $allowed, true), $access->can($userId, $this->project, $action), "$name / $action");
                $this->assertFalse($access->can($userId, $missingProject, $action), "$name / $action on a missing project");
            }
        }
    }
}
