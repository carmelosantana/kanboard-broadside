<?php

namespace Kanboard\Plugin\Broadside\Schema;

const VERSION = 1;

function version_1($pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS broadside_artifacts (
        id SERIAL PRIMARY KEY,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        task_id INTEGER NULL REFERENCES tasks(id) ON DELETE SET NULL,
        title VARCHAR(255) NOT NULL,
        type VARCHAR(16) NOT NULL,
        current_version INTEGER NOT NULL,
        source_url TEXT NULL,
        share_token VARCHAR(64) NULL,
        creator_id INTEGER NOT NULL,
        created_at BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        is_removed SMALLINT NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS broadside_artifacts_project_idx ON broadside_artifacts(project_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS broadside_artifacts_task_idx ON broadside_artifacts(task_id)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS broadside_artifacts_share_token_idx ON broadside_artifacts(share_token)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS broadside_versions (
        id SERIAL PRIMARY KEY,
        artifact_id INTEGER NOT NULL REFERENCES broadside_artifacts(id) ON DELETE CASCADE,
        number INTEGER NOT NULL,
        body TEXT NOT NULL,
        creator_id INTEGER NOT NULL,
        created_at BIGINT NOT NULL
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS broadside_versions_artifact_number_idx ON broadside_versions(artifact_id, number)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS broadside_comments (
        id SERIAL PRIMARY KEY,
        artifact_id INTEGER NOT NULL REFERENCES broadside_artifacts(id) ON DELETE CASCADE,
        version INTEGER NOT NULL,
        parent_id INTEGER NULL,
        user_id INTEGER NOT NULL,
        body TEXT NOT NULL,
        quote TEXT NULL,
        created_at BIGINT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS broadside_comments_artifact_idx ON broadside_comments(artifact_id)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS broadside_submissions (
        id SERIAL PRIMARY KEY,
        artifact_id INTEGER NOT NULL REFERENCES broadside_artifacts(id) ON DELETE CASCADE,
        version INTEGER NOT NULL,
        form_key VARCHAR(64) NOT NULL,
        user_id INTEGER NOT NULL,
        payload TEXT NOT NULL,
        created_at BIGINT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS broadside_submissions_artifact_idx ON broadside_submissions(artifact_id)');
}
