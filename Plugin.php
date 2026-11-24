<?php

namespace Kanboard\Plugin\Broadside;

use Kanboard\Core\Plugin\Base;
use Kanboard\Core\Security\Role;
use Kanboard\Plugin\Broadside\Api\BroadsideProcedure;
use Kanboard\Plugin\Broadside\Model\Access;
use Kanboard\Plugin\Broadside\Model\ArtifactModel;

class Plugin extends Base
{
    public function initialize(): void
    {
        $this->container['broadsideArtifactModel'] = static fn ($container) => new ArtifactModel($container);
        $this->route->addRoute('broadside/raw/:artifact_id/:version', 'RawController', 'show', 'Broadside');
        $this->route->addRoute('broadside/project/:project_id', 'ArtifactController', 'index', 'Broadside');
        $this->route->addRoute('broadside/artifact/:artifact_id', 'ArtifactController', 'show', 'Broadside');
        $this->route->addRoute('broadside/artifact/:artifact_id/submit', 'BridgeController', 'submit', 'Broadside');
        $this->route->addRoute('broadside/artifact/:artifact_id/results', 'BridgeController', 'results', 'Broadside');
        $this->route->addRoute('broadside/s/:token', 'ShareController', 'show', 'Broadside');
        $this->applicationAccessMap->add('ShareController', 'show', Role::APP_PUBLIC);

        $access = new Access($this->container);
        $canView = fn (int $projectId): bool => $access->can((int) $this->userSession->getId(), $projectId, 'view');
        $menu = fn (array $project): array => ['canViewArtifacts' => $canView((int) $project['id'])];
        foreach (['template:project:dropdown', 'template:project-header:view-switcher', 'template:project:sidebar'] as $hook) {
            $this->helper->hook->attachCallable($hook, 'Broadside:project/menu_item', $menu);
        }

        $this->helper->hook->attachCallable('template:task:sidebar:information', 'Broadside:task/sidebar',
            function (array $task) use ($canView): array {
                $artifacts = $canView((int) $task['project_id'])
                    ? $this->db->table('broadside_artifacts')->eq('task_id', $task['id'])
                        ->eq('project_id', $task['project_id'])->eq('is_removed', 0)
                        ->orderBy('updated_at', 'DESC')->orderBy('id', 'DESC')->findAll()
                    : [];
                return ['linkedArtifacts' => $artifacts];
            }
        );

        if (isset($this->container['api'])) {
            $this->api->getProcedureHandler()->withObject(new BroadsideProcedure($this->container));
        }
    }

    public function getPluginName(): string
    {
        return 'Broadside';
    }

    public function getPluginDescription(): string
    {
        return 'Publish project artifacts as sandboxed HTML or Markdown pages.';
    }

    public function getPluginVersion(): string
    {
        return '1.0.0';
    }

    public function getPluginAuthor(): string
    {
        return 'Carmelo Santana';
    }

    public function getPluginHomepage(): string
    {
        return 'https://github.com/carmelosantana/kanboard-broadside';
    }

    public function getPluginLicense(): string
    {
        return 'MIT';
    }

    public function getCompatibleVersion(): string
    {
        return '>=1.2.47';
    }
}
