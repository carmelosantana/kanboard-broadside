<?php

namespace Kanboard\Plugin\Broadside\Model;

use InvalidArgumentException;
use JsonRPC\Exception\AccessDeniedException;
use Kanboard\Core\Base;
use Kanboard\Core\Controller\AccessForbiddenException;

/** @internal Request validation and artifact response mapping, outside the registered procedure object. */
final class BroadsideProcedureModel extends Base
{
    public function publish(mixed $projectId, array $data): array
    {
        [$projectId, $userId] = $this->requirePublisher($projectId);
        $data['artifact_id'] = $this->integerParam($data['artifact_id'], 'artifact_id', 0);
        $data['task_id'] = $this->integerParam($data['task_id'], 'task_id', 0);
        if (! is_string($data['source_url'])) {
            throw new InvalidArgumentException('source_url must be a string.');
        }

        $result = $this->container['broadsideArtifactModel']->publish($projectId, $userId, $data);
        $warnings = $data['type'] === 'html' ? Lint::check($data['body']) : [];

        return $result + ['url' => $this->viewerUrl($result['artifact_id']), 'warnings' => $warnings];
    }

    public function import(
        mixed $projectId, mixed $title, mixed $content, mixed $format, mixed $sourceUrl, mixed $artifactId
    ): array {
        [$projectId, $userId] = $this->requirePublisher($projectId);
        $artifactId = $this->integerParam($artifactId, 'artifact_id', 0);
        if (! is_string($sourceUrl)) {
            throw new InvalidArgumentException('source_url must be a string.');
        }
        if ($artifactId !== 0 && (int) ($this->container['broadsideArtifactModel']->find($artifactId)['project_id'] ?? 0) !== $projectId) {
            throw new InvalidArgumentException('Artifact is unavailable in this project.');
        }

        $result = (new Importer($this->container))->import(
            $projectId, $userId, $title, $content, $format, $sourceUrl, $artifactId
        );

        return $result + ['url' => $this->viewerUrl($result['artifact_id'])];
    }

    public function get(mixed $artifactId, mixed $version): ?array
    {
        $userId = $this->requireUser();
        $artifactId = $this->integerParam($artifactId, 'artifact_id', 1);
        $version = $this->integerParam($version, 'version', 0);
        $artifact = $this->container['broadsideArtifactModel']->find($artifactId);
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
        $limit = $this->integerParam($limit, 'limit', PHP_INT_MIN);
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
        $artifact = $this->container['broadsideArtifactModel']->find($artifactId);
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
        $artifact = $this->container['broadsideArtifactModel']->find($artifactId);
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
        $artifact = $this->container['broadsideArtifactModel']->find($artifactId);
        if ($artifact === null || ! (new Access($this->container))->can($userId, (int) $artifact['project_id'], 'remove')) {
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

    private function requirePublisher(mixed $projectId): array
    {
        $userId = $this->requireUser();
        $projectId = $this->integerParam($projectId, 'project_id', 1);
        if (! (new Access($this->container))->can($userId, $projectId, 'publish')) {
            throw new AccessDeniedException();
        }

        return [$projectId, $userId];
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
