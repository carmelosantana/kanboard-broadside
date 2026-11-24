<?php

namespace Kanboard\Plugin\Broadside\Model;

use InvalidArgumentException;
use Kanboard\Core\Base;
use Kanboard\Core\Controller\AccessForbiddenException;
use Throwable;

/** Artifact storage and share link management. */
class ArtifactModel extends Base
{
    private const MAX_BODY_BYTES = 5 * 1024 * 1024;

    public function publish(int $projectId, int $userId, array $data): array
    {
        $type = $data['type'] ?? null;
        $body = $data['body'] ?? null;
        $title = $data['title'] ?? null;
        $artifactId = (int) ($data['artifact_id'] ?? 0);
        $taskId = (int) ($data['task_id'] ?? 0);
        $sourceUrl = $data['source_url'] ?? null;

        if (! in_array($type, ['html', 'markdown'], true)) {
            throw new InvalidArgumentException('Artifact type must be html or markdown.');
        }
        if (! is_string($body) || strlen($body) > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException('Artifact body must be a string of at most 5 MiB.');
        }
        if (! is_string($title)) {
            throw new InvalidArgumentException('Artifact title must be a string.');
        }
        if ($taskId !== 0 && $this->taskFinderModel->getProjectId($taskId) !== $projectId) {
            throw new InvalidArgumentException('Artifact task must belong to the project.');
        }

        $taskId = $taskId ?: null;
        $sourceUrl = $sourceUrl === '' ? null : $sourceUrl;
        $now = time();
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();

        try {
            if ($artifactId === 0) {
                $pdo->prepare('INSERT INTO broadside_artifacts
                    (project_id, task_id, title, type, current_version, source_url, creator_id, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)')
                    ->execute([$projectId, $taskId, $title, $type, $sourceUrl, $userId, $now, $now]);
                $artifactId = $this->db->getLastId();
                $version = 1;
            } else {
                // Increment under the write lock so concurrent publishes get different version numbers.
                $update = $pdo->prepare('UPDATE broadside_artifacts
                    SET current_version = current_version + 1, updated_at = ?, title = ?, type = ?,
                        task_id = COALESCE(?, task_id), source_url = COALESCE(?, source_url)
                    WHERE id = ? AND project_id = ? AND is_removed = 0');
                $update->execute([$now, $title, $type, $taskId, $sourceUrl, $artifactId, $projectId]);
                if ($update->rowCount() !== 1) {
                    throw new InvalidArgumentException('Artifact is unavailable in this project.');
                }

                $version = (int) $this->db->table('broadside_artifacts')->eq('id', $artifactId)
                    ->findOneColumn('current_version');
            }

            $pdo->prepare('INSERT INTO broadside_versions (artifact_id, number, body, creator_id, created_at)
                VALUES (?, ?, ?, ?, ?)')->execute([$artifactId, $version, $body, $userId, $now]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['artifact_id' => $artifactId, 'version' => $version];
    }

    public function getVersion(int $id, int $number): ?string
    {
        $body = $this->db->table('broadside_versions')->eq('artifact_id', $id)->eq('number', $number)
            ->findOneColumn('body');

        return $body === false ? null : $body;
    }

    public function listByProject(int $projectId): array
    {
        return $this->db->table('broadside_artifacts')->eq('project_id', $projectId)->eq('is_removed', 0)
            ->orderBy('updated_at', 'DESC')->orderBy('id', 'DESC')->findAll();
    }

    public function remove(int $id): bool
    {
        $result = $this->db->execute('UPDATE broadside_artifacts SET is_removed = 1 WHERE id = ? AND is_removed = 0', [$id]);

        return $result !== false && $result->rowCount() === 1;
    }

    public function setShare(int $id, int $userId, bool $enabled): ?string
    {
        $artifact = $this->db->table('broadside_artifacts')->eq('id', $id)->eq('is_removed', 0)->findOne();
        if (empty($artifact) || ! (new Access($this->container))->can($userId, (int) $artifact['project_id'], 'share')) {
            throw new AccessForbiddenException();
        }

        if (! $enabled) {
            $this->db->execute('UPDATE broadside_artifacts SET share_token = NULL WHERE id = ? AND is_removed = 0', [$id]);
            return null;
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE broadside_artifacts SET share_token = COALESCE(share_token, ?)
                WHERE id = ? AND is_removed = 0')->execute([$token, $id]);
            $stored = $this->db->table('broadside_artifacts')->eq('id', $id)->eq('is_removed', 0)->findOne();
            if (empty($stored)) {
                throw new AccessForbiddenException();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->shareUrl($stored['share_token']);
    }

    public function shareUrl(string $token): string
    {
        return $this->helper->url->to('ShareController', 'show', [
            'plugin' => 'Broadside', 'token' => $token,
        ], '', true);
    }
}
