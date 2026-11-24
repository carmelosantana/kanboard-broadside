<?php

use KanboardTests\units\Base;

require_once __DIR__.'/BroadsideHarness.php';

class SchemaTest extends Base
{
    use BroadsideHarness;

    public function testVersionUniquenessAndProjectCascade(): void
    {
        $this->setUpBroadside();
        $pdo = $this->container['db']->getConnection();
        $now = time();
        $user = $this->users['member'];

        $pdo->prepare('INSERT INTO broadside_artifacts
            (project_id, task_id, title, type, current_version, source_url, share_token, creator_id, created_at, updated_at)
            VALUES (?, NULL, ?, ?, 1, NULL, NULL, ?, ?, ?)')
            ->execute([$this->project, 'Report', 'markdown', $user, $now, $now]);
        $artifact = $pdo->query('SELECT id, is_removed FROM broadside_artifacts')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int) $artifact['is_removed']);
        $id = (int) $artifact['id'];

        $version = $pdo->prepare('INSERT INTO broadside_versions
            (artifact_id, number, body, creator_id, created_at) VALUES (?, 1, ?, ?, ?)');
        $version->execute([$id, '# Report', $user, $now]);
        $pdo->prepare('INSERT INTO broadside_comments
            (artifact_id, version, parent_id, user_id, body, quote, created_at) VALUES (?, 1, NULL, ?, ?, NULL, ?)')
            ->execute([$id, $user, 'A comment', $now]);
        $pdo->prepare('INSERT INTO broadside_submissions
            (artifact_id, version, form_key, user_id, payload, created_at) VALUES (?, 1, ?, ?, ?, ?)')
            ->execute([$id, 'poll', $user, '{"vote":"yes"}', $now]);

        $duplicateError = null;
        try {
            $version->execute([$id, '# Duplicate', $user, $now]);
        } catch (PDOException $e) {
            $duplicateError = $e;
        }
        $this->assertInstanceOf(PDOException::class, $duplicateError, 'Duplicate version was accepted');

        $tables = ['broadside_artifacts', 'broadside_versions', 'broadside_comments', 'broadside_submissions'];
        foreach ($tables as $table) {
            $this->assertSame(1, (int) $pdo->query("SELECT COUNT(id) FROM $table")->fetchColumn(), $table);
        }
        $pdo->prepare('DELETE FROM projects WHERE id = ?')->execute([$this->project]);
        foreach ($tables as $table) {
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(id) FROM $table")->fetchColumn(), $table);
        }
    }
}
