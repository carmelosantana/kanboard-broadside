<?php

namespace Kanboard\Plugin\Broadside\Model;

use DomainException;
use InvalidArgumentException;
use Kanboard\Core\Base;
use LengthException;
use OutOfBoundsException;
use OverflowException;
use PDO;
use stdClass;
use Throwable;

/** Submission storage. Callers authorize user actions through Access. */
class SubmissionModel extends Base
{
    public const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 500;
    private const MAX_PAYLOAD_BYTES = 64 * 1024;
    private const MAX_PER_MINUTE = 10;

    public function create(int $artifactId, int $userId, mixed $version, mixed $formKey, mixed $payload, bool $once): int
    {
        $formKey = $this->validateFormKey($formKey);
        $version = filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($version === false) {
            throw new InvalidArgumentException('Version must be a positive integer.');
        }
        if (! is_string($payload)) {
            throw new InvalidArgumentException('Payload must be a JSON object.');
        }
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new LengthException('Payload must be at most 64 KiB.');
        }
        $data = json_decode($payload);
        // Leave room for the results and JSON-RPC envelopes when encoding the payload.
        if (! $data instanceof stdClass || json_encode($data, 0, 508) === false) {
            throw new InvalidArgumentException('Payload must be a JSON object that can be returned in results.');
        }

        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        try {
            // Take a write lock before reading so concurrent submissions cannot race the once check.
            $pdo->prepare('UPDATE broadside_artifacts SET current_version = current_version WHERE id = ? AND is_removed = 0')
                ->execute([$artifactId]);
            $available = $pdo->prepare(
                'SELECT 1 FROM broadside_versions v JOIN broadside_artifacts a ON a.id = v.artifact_id
                 WHERE a.id = ? AND a.is_removed = 0 AND v.number = ?'
            );
            $available->execute([$artifactId, $version]);
            if ($available->fetchColumn() === false) {
                throw new OutOfBoundsException('Artifact version is unavailable.');
            }

            if ($this->db->table('broadside_submissions')->eq('artifact_id', $artifactId)->eq('user_id', $userId)
                ->gt('created_at', time() - 60)->count() >= self::MAX_PER_MINUTE) {
                throw new OverflowException('Too many submissions. Try again in a minute.');
            }

            if ($once && $this->db->table('broadside_submissions')->eq('artifact_id', $artifactId)
                ->eq('form_key', $formKey)->eq('user_id', $userId)->exists()) {
                throw new DomainException('You already submitted this form.');
            }

            $pdo->prepare('INSERT INTO broadside_submissions (artifact_id, version, form_key, user_id, payload, created_at)
                VALUES (?, ?, ?, ?, ?, ?)')->execute([$artifactId, $version, $formKey, $userId, $payload, time()]);
            $id = (int) $this->db->getLastId();
            $pdo->commit();

            return $id;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function results(int $artifactId, int $userId, mixed $formKey): array
    {
        $formKey = $this->validateFormKey($formKey);
        $sql = 'SELECT u.username, s.payload, s.created_at, totals.submission_count, totals.mine
                FROM broadside_submissions s
                JOIN broadside_artifacts a ON a.id = s.artifact_id
                LEFT JOIN users u ON u.id = s.user_id
                CROSS JOIN (
                    SELECT COUNT(*) AS submission_count, MAX(CASE WHEN user_id = ? THEN 1 ELSE 0 END) AS mine
                    FROM broadside_submissions WHERE artifact_id = ? AND form_key = ?
                ) totals
                WHERE s.artifact_id = ? AND s.form_key = ? AND a.is_removed = 0
                ORDER BY s.id DESC LIMIT '.self::MAX_LIMIT;
        $rows = $this->db->execute($sql, [$userId, $artifactId, $formKey, $artifactId, $formKey])->fetchAll(PDO::FETCH_ASSOC);

        return [
            'form_key' => $formKey,
            'count' => (int) ($rows[0]['submission_count'] ?? 0),
            'rows' => array_map(static fn (array $row): array => [
                'username' => $row['username'],
                'data' => json_decode($row['payload']),
                'created_at' => (int) $row['created_at'],
            ], $rows),
            'mine' => (bool) ($rows[0]['mine'] ?? false),
        ];
    }

    public function listByArtifact(int $artifactId, mixed $formKey = '', int $sinceId = 0, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = $limit <= 0 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);
        $sql = 'SELECT s.*, u.username FROM broadside_submissions s
                JOIN broadside_artifacts a ON a.id = s.artifact_id
                LEFT JOIN users u ON u.id = s.user_id
                WHERE s.artifact_id = ? AND s.id > ? AND a.is_removed = 0';
        $params = [$artifactId, $sinceId];
        if ($formKey !== '') {
            $sql .= ' AND s.form_key = ?';
            $params[] = $this->validateFormKey($formKey);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'artifact_id' => (int) $row['artifact_id'],
            'version' => (int) $row['version'],
            'form_key' => $row['form_key'],
            'user_id' => (int) $row['user_id'],
            'username' => $row['username'],
            'data' => json_decode($row['payload']),
            'created_at' => (int) $row['created_at'],
        ], $this->db->execute($sql.' ORDER BY s.id ASC LIMIT '.$limit, $params)->fetchAll(PDO::FETCH_ASSOC));
    }

    private function validateFormKey(mixed $formKey): string
    {
        if (! is_string($formKey) || ! preg_match('/\A[a-z0-9_-]{1,64}\z/', $formKey)) {
            throw new InvalidArgumentException('Form key must contain 1–64 lowercase letters, digits, underscores or hyphens.');
        }

        return $formKey;
    }
}
