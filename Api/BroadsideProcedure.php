<?php

namespace Kanboard\Plugin\Broadside\Api;

use InvalidArgumentException;
use JsonRPC\Exception\AccessDeniedException;
use Kanboard\Core\Base;
use Kanboard\Plugin\Broadside\Model\Access;
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

    public function artifactRemove($artifact_id): bool
    {
        return $this->model->remove($artifact_id);
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
