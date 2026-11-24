<?php

namespace Kanboard\Plugin\Broadside\Api;

use InvalidArgumentException;
use JsonRPC\Exception\AccessDeniedException;
use Kanboard\Core\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\Broadside\Model\Access;
use Kanboard\Plugin\Broadside\Model\CommentModel;
use Kanboard\Plugin\Broadside\Model\SubmissionModel;
use Pimple\Container;

final class BroadsideProcedure
{
    private readonly BroadsideProcedureModel $model;

    public function __construct(Container $container)
    {
        $this->model = new BroadsideProcedureModel($container);
    }

    public function artifactPublish($project_id, $title, $body, $type = 'html', $artifact_id = 0, $task_id = 0, $source_url = ''): array
    {
        return $this->model->publish($project_id, compact('title', 'body', 'type', 'artifact_id', 'task_id', 'source_url'));
    }

    public function artifactGet($artifact_id, $version = 0): ?array
    {
        return $this->model->get($artifact_id, $version);
    }

    public function artifactList($project_id): array
    {
        return $this->model->list($project_id);
    }

    public function artifactGetContract(): string
    {
        return $this->model->getContract();
    }

    public function artifactCommentList($project_id, $since_id = 0, $limit = 100, $artifact_id = 0): array
    {
        return $this->model->listComments($project_id, $since_id, $limit, $artifact_id);
    }

    public function artifactCommentCreate($artifact_id, $body, $parent_id = 0, $quote = ''): int
    {
        return $this->model->createComment($artifact_id, $body, $parent_id, $quote);
    }

    public function artifactSubmissionList($artifact_id, $form_key = '', $since_id = 0): array
    {
        return $this->model->listSubmissions($artifact_id, $form_key, $since_id);
    }

    public function artifactRemove($artifact_id): bool
    {
        return $this->model->remove($artifact_id);
    }

    public function artifactShare($artifact_id, $enabled): array
    {
        return $this->model->share($artifact_id, $enabled);
    }
}

/** @internal Request validation and artifact response mapping, outside the registered procedure object. */
final class BroadsideProcedureModel extends Base
{
    public function publish(mixed $projectId, array $data): array
    {
        $userId = $this->requireUser();
        $projectId = $this->integerParam($projectId, 'project_id', 1);
        if (! (new Access($this->container))->can($userId, $projectId, 'publish')) {
            throw new AccessDeniedException();
        }

        $data['artifact_id'] = $this->integerParam($data['artifact_id'], 'artifact_id', 0);
        $data['task_id'] = $this->integerParam($data['task_id'], 'task_id', 0);
        if (! is_string($data['source_url'])) {
            throw new InvalidArgumentException('source_url must be a string.');
        }

        $result = $this->container['broadsideArtifactModel']->publish($projectId, $userId, $data);

        return $result + ['url' => $this->viewerUrl($result['artifact_id']), 'warnings' => []];
    }

    public function get(mixed $artifactId, mixed $version): ?array
    {
        $userId = $this->requireUser();
        $artifactId = $this->integerParam($artifactId, 'artifact_id', 1);
        $version = $this->integerParam($version, 'version', 0);
        $artifact = $this->findArtifact($artifactId);
        if (empty($artifact) || ! (new Access($this->container))->can($userId, (int) $artifact['project_id'], 'view')) {
            return null;
        }

        $version = $version ?: (int) $artifact['current_version'];
        $body = $this->container['broadsideArtifactModel']->getVersion($artifactId, $version);
        if ($body === null) {
            return null;
        }

        return $this->summary($artifact) + [
            'project_id' => (int) $artifact['project_id'],
            'task_id' => $artifact['task_id'] === null ? null : (int) $artifact['task_id'],
            'source_url' => $artifact['source_url'],
            'creator_id' => (int) $artifact['creator_id'],
            'created_at' => (int) $artifact['created_at'],
            'version' => $version,
            'body' => $body,
        ];
    }

    public function list(mixed $projectId): array
    {
        $userId = $this->requireUser();
        $projectId = $this->integerParam($projectId, 'project_id', 1);
        if (! (new Access($this->container))->can($userId, $projectId, 'view')) {
            throw new AccessDeniedException();
        }

        return array_map($this->summary(...), $this->container['broadsideArtifactModel']->listByProject($projectId));
    }

    public function getContract(): string
    {
        $this->requireUser();

        return file_get_contents(__DIR__.'/../CONTRACT.md');
    }

    public function listComments(mixed $projectId, mixed $sinceId, mixed $limit, mixed $artifactId): array
    {
        $userId = $this->requireUser();
        $projectId = $this->integerParam($projectId, 'project_id', 1);
        if (! (new Access($this->container))->can($userId, $projectId, 'view')) {
            throw new AccessDeniedException();
        }

        $sinceId = $this->integerParam($sinceId, 'since_id', 0);
        $limit = $this->integerParam($limit, 'limit', 0);
        $artifactId = $this->integerParam($artifactId, 'artifact_id', 0);
        $comments = new CommentModel($this->container);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'artifact_id' => (int) $row['artifact_id'],
            'artifact_title' => $row['artifact_title'],
            'url' => $comments->permalink((int) $row['artifact_id'], (int) $row['version'], 'comment-'.$row['id'], true),
            'user_id' => (int) $row['user_id'],
            'username' => $row['username'],
            'body' => $row['body'],
            'quote' => $row['quote'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'version' => (int) $row['version'],
            'created_at' => (int) $row['created_at'],
            'publisher_username' => $row['publisher_username'],
        ], $comments->listByProject($projectId, $sinceId, $limit, $artifactId));
    }

    public function createComment(mixed $artifactId, mixed $body, mixed $parentId, mixed $quote): int
    {
        $userId = $this->requireUser();
        $artifactId = $this->integerParam($artifactId, 'artifact_id', 1);
        $artifact = $this->findArtifact($artifactId);
        if (empty($artifact) || ! (new Access($this->container))->can($userId, (int) $artifact['project_id'], 'comment')) {
            throw new AccessDeniedException();
        }

        $parentId = $this->integerParam($parentId, 'parent_id', 0);

        return (new CommentModel($this->container))->create($artifactId, $userId, $body, $parentId, $quote);
    }

    public function listSubmissions(mixed $artifactId, mixed $formKey, mixed $sinceId): array
    {
        $userId = $this->requireUser();
        $artifactId = $this->integerParam($artifactId, 'artifact_id', 1);
        $artifact = $this->findArtifact($artifactId);
        if (empty($artifact) || ! (new Access($this->container))->can($userId, (int) $artifact['project_id'], 'view')) {
            throw new AccessDeniedException();
        }

        $sinceId = $this->integerParam($sinceId, 'since_id', 0);

        return (new SubmissionModel($this->container))->listByArtifact($artifactId, $formKey, $sinceId);
    }

    public function remove(mixed $artifactId): bool
    {
        $userId = $this->requireUser();
        $artifactId = $this->integerParam($artifactId, 'artifact_id', 1);
        $artifact = $this->findArtifact($artifactId);
        if (empty($artifact)) {
            return false;
        }
        if (! (new Access($this->container))->can($userId, (int) $artifact['project_id'], 'remove')) {
            throw new AccessDeniedException();
        }

        return $this->container['broadsideArtifactModel']->remove($artifactId);
    }

    public function share(mixed $artifactId, mixed $enabled): array
    {
        $userId = $this->requireUser();
        $artifactId = $this->integerParam($artifactId, 'artifact_id', 1);
        if (! is_bool($enabled)) {
            throw new InvalidArgumentException('enabled must be a boolean.');
        }

        try {
            return ['url' => $this->container['broadsideArtifactModel']->setShare($artifactId, $userId, $enabled)];
        } catch (AccessForbiddenException $e) {
            throw new AccessDeniedException();
        }
    }

    private function requireUser(): int
    {
        $userId = (int) $this->userSession->getId();
        if ($userId <= 0 || (isset($this->container['api']) && $this->api->getUsername() === 'jsonrpc')) {
            throw new AccessDeniedException();
        }

        return $userId;
    }

    private function integerParam(mixed $value, string $name, int $minimum): int
    {
        $integer = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($integer === false || $integer < $minimum) {
            throw new InvalidArgumentException($name.' must be an integer of at least '.$minimum.'.');
        }

        return $integer;
    }

    private function findArtifact(int $artifactId): ?array
    {
        return $this->db->table('broadside_artifacts')->eq('id', $artifactId)->eq('is_removed', 0)->findOne();
    }

    private function summary(array $artifact): array
    {
        $id = (int) $artifact['id'];
        $publisher = $this->userModel->getById($artifact['creator_id']);

        return [
            'artifact_id' => $id,
            'title' => $artifact['title'],
            'type' => $artifact['type'],
            'current_version' => (int) $artifact['current_version'],
            'updated_at' => (int) $artifact['updated_at'],
            'url' => $this->viewerUrl($id),
            'publisher_username' => $publisher['username'] ?? null,
        ];
    }

    private function viewerUrl(int $artifactId): string
    {
        return $this->helper->url->to('ArtifactController', 'show', [
            'plugin' => 'Broadside',
            'artifact_id' => $artifactId,
        ], '', true);
    }
}
