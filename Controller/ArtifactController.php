<?php

namespace Kanboard\Plugin\Broadside\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Plugin\Broadside\Model\Access;

class ArtifactController extends BaseController
{
    public function index(): void
    {
        $projectId = $this->request->getIntegerParam('project_id');
        if (! (new Access($this->container))->can((int) $this->userSession->getId(), $projectId, 'view')) {
            $this->response->status(404);
            return;
        }

        $query = $this->request->getStringParam('search');
        $search = is_string($query) ? trim($query) : '';
        $artifacts = $this->container['broadsideArtifactModel']->listByProject($projectId);
        $hasArtifacts = $artifacts !== [];
        if ($search !== '') {
            $artifacts = array_filter($artifacts, static fn ($artifact) => mb_stripos($artifact['title'], $search) !== false);
        }

        $publishers = [];
        if ($artifacts !== []) {
            $creatorIds = array_unique(array_column($artifacts, 'creator_id'));
            $users = $this->db->table('users')->columns('id', 'name', 'username')->in('id', $creatorIds)->findAll();
            foreach ($users as $user) {
                $publishers[$user['id']] = $user['name'] ?: $user['username'];
            }
        }

        $this->renderProject('list', [
            'project' => $this->projectModel->getById($projectId),
            'title' => t('Artifacts'),
            'artifacts' => $artifacts,
            'hasArtifacts' => $hasArtifacts,
            'publishers' => $publishers,
            'search' => $search,
        ]);
    }

    public function show(): void
    {
        $artifactId = $this->request->getIntegerParam('artifact_id');
        $artifact = $this->db->table('broadside_artifacts')
            ->eq('id', $artifactId)->eq('is_removed', 0)->findOne();

        if (empty($artifact) || ! (new Access($this->container))->can(
            (int) $this->userSession->getId(), (int) $artifact['project_id'], 'view'
        )) {
            $this->response->status(404);
            return;
        }

        $version = filter_var(
            $this->request->getStringParam('version', (string) $artifact['current_version']),
            FILTER_VALIDATE_INT
        );
        $versions = array_map('intval', $this->db->table('broadside_versions')->eq('artifact_id', $artifactId)
            ->orderBy('number', 'DESC')->findAllByColumn('number'));

        if (! in_array($version, $versions, true)) {
            $this->response->status(404);
            return;
        }

        $theme = $this->userSession->getTheme();
        $preset = $this->configModel->get('harbor_preset', 'newburgh');
        $rawUrl = $this->helper->url->to('RawController', 'show', [
            'plugin' => 'Broadside', 'artifact_id' => $artifactId, 'version' => $version,
        ]);
        $rawUrl .= (str_contains($rawUrl, '?') ? '&' : '?').http_build_query([
            'theme' => $theme, 'preset' => $preset,
        ]);

        $this->renderProject('show', [
            'project' => $this->projectModel->getById($artifact['project_id']),
            'title' => $artifact['title'],
            'artifact' => $artifact,
            'version' => $version,
            'versions' => $versions,
            'rawUrl' => $rawUrl,
            'theme' => $theme,
            'preset' => $preset,
            'csrf' => $this->token->getReusableCSRFToken(),
        ]);
    }

    private function renderProject(string $template, array $params): void
    {
        $this->hook->on('template:layout:css', ['template' => 'plugins/Broadside/Assets/css/viewer.css']);
        $this->response->html($this->helper->layout->project('Broadside:artifact/'.$template, $params));
    }
}
