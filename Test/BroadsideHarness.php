<?php

use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Broadside\Plugin;

trait BroadsideHarness
{
    private int $project;
    private int $otherProject;
    /** @var array<string, int> */
    private array $users = [];

    private function setUpBroadside(): void
    {
        require_once __DIR__.'/../Schema/'.ucfirst(DB_DRIVER).'.php';
        \Kanboard\Plugin\Broadside\Schema\version_1($this->container['db']->getConnection());
        (new Plugin($this->container))->initialize();

        $this->project = (int) $this->container['projectModel']->create(['name' => 'Pages']);
        $this->otherProject = (int) $this->container['projectModel']->create(['name' => 'Other']);
        foreach ([
            'viewer' => Role::PROJECT_VIEWER,
            'member' => Role::PROJECT_MEMBER,
            'manager' => Role::PROJECT_MANAGER,
        ] as $name => $role) {
            $this->users[$name] = (int) $this->container['userModel']->create(['username' => $name]);
            $this->container['projectUserRoleModel']->addUser($this->project, $this->users[$name], $role);
        }
        $this->users['outsider'] = (int) $this->container['userModel']->create(['username' => 'outsider']);
        $this->container['projectUserRoleModel']->addUser($this->otherProject, $this->users['outsider'], Role::PROJECT_MANAGER);
    }
}
