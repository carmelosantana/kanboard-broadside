<?php

namespace Kanboard\Plugin\Broadside\Model;

use InvalidArgumentException;
use Kanboard\Core\Base;
use PDO;
use RuntimeException;

/** Comment storage. Callers authorize user actions through Access. */
class CommentModel extends Base
{
    public const DEFAULT_LIMIT = 100;

    public function create(
        int $artifactId,
        int $userId,
        mixed $body,
        int $parentId = 0,
        mixed $quote = '',
        ?int $version = null
    ): int {
        if (! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException('Comment body must be a non-empty string.');
        }
        if (mb_strlen($body, 'UTF-8') > 20000) {
            throw new InvalidArgumentException('Comment body must be at most 20,000 characters.');
        }
        if (! is_string($quote)) {
            throw new InvalidArgumentException('Comment quote must be a string.');
        }
        $quote = mb_substr($quote, 0, 1000, 'UTF-8');
        if ($parentId !== 0 && ! $this->db->table('broadside_comments')
            ->eq('artifact_id', $artifactId)->eq('id', $parentId)->exists()) {
            throw new InvalidArgumentException('Parent comment must belong to the artifact.');
        }
        if ($version !== null && ! $this->db->table('broadside_versions')
            ->eq('artifact_id', $artifactId)->eq('number', $version)->exists()) {
            throw new InvalidArgumentException('Artifact version is unavailable.');
        }

        $result = $this->db->execute(
            'INSERT INTO broadside_comments (artifact_id, version, parent_id, user_id, body, quote, created_at)
             SELECT id, COALESCE(?, current_version), ?, ?, ?, ?, ?
             FROM broadside_artifacts WHERE id = ? AND is_removed = 0',
            [$version, $parentId ?: null, $userId, $body, $quote === '' ? null : $quote, time(), $artifactId]
        );
        if ($result === false) {
            throw new RuntimeException('Unable to store the comment.');
        }
        if ($result->rowCount() !== 1) {
            throw new InvalidArgumentException('Artifact is unavailable.');
        }

        return (int) $this->db->getLastId();
    }

    public function listByProject(int $projectId, int $sinceId = 0, int $limit = self::DEFAULT_LIMIT, int $artifactId = 0): array
    {
        $limit = $limit <= 0 ? self::DEFAULT_LIMIT : min($limit, 500);
        return $this->fetch($projectId, $sinceId, $artifactId, $limit);
    }

    public function listByArtifact(int $projectId, int $artifactId): array
    {
        return $this->fetch($projectId, 0, $artifactId, null);
    }

    public function permalink(int $artifactId, int $version, string $anchor = '', bool $absolute = false): string
    {
        $url = $this->helper->url->to('ArtifactController', 'show', [
            'plugin' => 'Broadside', 'artifact_id' => $artifactId,
        ], '', $absolute);

        return $url.(str_contains($url, '?') ? '&' : '?').'version='.$version.($anchor === '' ? '' : '#'.$anchor);
    }

    private function fetch(int $projectId, int $sinceId, int $artifactId, ?int $limit): array
    {
        $sql = 'SELECT c.*, a.title AS artifact_title, author.username, publisher.username AS publisher_username
                FROM broadside_comments c
                JOIN broadside_artifacts a ON a.id = c.artifact_id
                LEFT JOIN users author ON author.id = c.user_id
                LEFT JOIN users publisher ON publisher.id = a.creator_id
                WHERE a.project_id = ? AND a.is_removed = 0 AND c.id > ?';
        $params = [$projectId, $sinceId];
        if ($artifactId !== 0) {
            $sql .= ' AND c.artifact_id = ?';
            $params[] = $artifactId;
        }
        $sql .= ' ORDER BY c.id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT '.$limit;
        }

        return $this->db->execute($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
}
