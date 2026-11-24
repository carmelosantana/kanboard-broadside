<?php

namespace Kanboard\Plugin\Broadside\Controller;

use InvalidArgumentException;
use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Broadside\Model\Access;
use Kanboard\Plugin\Broadside\Model\CommentModel;
use Kanboard\Plugin\Broadside\Model\Importer;

class ArtifactController extends BaseController
{
    public function index(): void
    {
        $projectId = $this->request->getIntegerParam('project_id');
        $userId = (int) $this->userSession->getId();
        $access = new Access($this->container);
        if (! $access->can($userId, $projectId, 'view')) {
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
            'canPublish' => $access->can($userId, $projectId, 'publish'),
        ]);
    }

    public function upload(): void
    {
        $projectId = $this->request->getIntegerParam('project_id');
        $userId = (int) $this->userSession->getId();
        if (! (new Access($this->container))->can($userId, $projectId, 'publish')) {
            throw new AccessForbiddenException();
        }

        $params = ['project' => $this->projectModel->getById($projectId), 'title' => t('Upload artifact')];
        if (! $this->request->isPost()) {
            $this->renderProject('upload', $params);
            return;
        }

        $this->checkCSRFForm();
        $file = $this->request->getFileInfo('artifact');
        $title = $this->request->getRawValue('title');
        $filename = $file['name'] ?? '';
        $type = match (is_string($filename) ? strtolower(pathinfo($filename, PATHINFO_EXTENSION)) : '') {
            'html' => 'html',
            'md' => 'markdown',
            default => null,
        };

        try {
            if (! is_string($title) || trim($title) === '') {
                throw new InvalidArgumentException(t('Enter an artifact title.'));
            }
            if ($type === null || ($file['error'] ?? null) !== UPLOAD_ERR_OK
                || ($file['size'] ?? PHP_INT_MAX) > Importer::MAX_CONTENT_BYTES) {
                throw new InvalidArgumentException(t('Choose an .html or .md file of at most 5 MiB.'));
            }

            $result = $this->container['broadsideArtifactModel']->publish($projectId, $userId, [
                'title' => trim($title),
                'type' => $type,
                'body' => $this->request->getFileContent('artifact'),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->renderProject('upload', $params + [
                'error' => $e->getMessage(),
                'artifactTitle' => is_string($title) ? $title : '',
            ], 400);
            return;
        }

        $this->response->redirect($this->helper->url->to('ArtifactController', 'show', [
            'plugin' => 'Broadside', 'artifact_id' => $result['artifact_id'],
        ]));
    }

    public function show(): void
    {
        $artifactId = $this->request->getIntegerParam('artifact_id');
        $artifact = $this->findArtifact($artifactId);
        $access = new Access($this->container);
        $userId = (int) $this->userSession->getId();

        if (empty($artifact) || ! $access->can($userId, (int) $artifact['project_id'], 'view')) {
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
        $canShare = $access->can($userId, (int) $artifact['project_id'], 'share');
        $shareUrl = $canShare && $artifact['share_token'] !== null
            ? $this->container['broadsideArtifactModel']->shareUrl($artifact['share_token'])
            : null;
        if ($shareUrl !== null) {
            $this->response->withHeader('Cache-Control', 'private, no-store');
        }

        $this->renderProject('comments', [
            'project' => $this->projectModel->getById($artifact['project_id']),
            'title' => $artifact['title'],
            'artifact' => $artifact,
            'version' => $version,
            'versions' => $versions,
            'rawUrl' => $rawUrl,
            'theme' => $theme,
            'preset' => $preset,
            'csrf' => $this->token->getReusableCSRFToken(),
            'canShare' => $canShare,
            'shareUrl' => $shareUrl,
            'comments' => (new CommentModel($this->container))->listByArtifact((int) $artifact['project_id'], $artifactId),
        ]);
    }

    public function comment(): void
    {
        if (! $this->request->isPost()) {
            $this->response->status(405);
            return;
        }
        $this->checkCSRFForm();

        $artifactId = $this->request->getIntegerParam('artifact_id');
        $artifact = $this->findArtifact($artifactId);
        $userId = (int) $this->userSession->getId();
        if (empty($artifact) || ! (new Access($this->container))->can($userId, (int) $artifact['project_id'], 'comment')) {
            throw new AccessForbiddenException();
        }

        $version = filter_var($this->request->getRawValue('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $parentId = filter_var($this->request->getRawValue('parent_id') ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($version === false || $parentId === false) {
            $this->response->status(400);
            return;
        }

        $comments = new CommentModel($this->container);
        try {
            $id = $comments->create(
                $artifactId, $userId, $this->request->getRawValue('body'), $parentId,
                $this->request->getRawValue('quote') ?? '', $version
            );
        } catch (InvalidArgumentException $e) {
            $this->flash->failure($e->getMessage());
            $this->response->redirect($comments->permalink($artifactId, (int) $artifact['current_version'], 'broadside-comments'));
            return;
        }

        $this->response->redirect($comments->permalink($artifactId, $version, 'comment-'.$id));
    }

    private function findArtifact(int $artifactId): ?array
    {
        return $this->db->table('broadside_artifacts')->eq('id', $artifactId)->eq('is_removed', 0)->findOne();
    }

    private function renderProject(string $template, array $params, int $status = 200): void
    {
        $this->hook->on('template:layout:css', ['template' => 'plugins/Broadside/Assets/css/viewer.css']);
        $this->response->html($this->helper->layout->project('Broadside:artifact/'.$template, $params), $status);
    }
}
