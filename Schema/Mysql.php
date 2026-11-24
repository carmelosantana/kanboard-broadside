<?php

namespace Kanboard\Plugin\Broadside\Schema;

const VERSION = 1;

function version_1($pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS broadside_artifacts (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        task_id INT NULL,
        title VARCHAR(255) NOT NULL,
        type VARCHAR(16) NOT NULL,
        current_version INT NOT NULL,
        source_url TEXT NULL,
        share_token VARCHAR(64) NULL,
        creator_id INT NOT NULL,
        created_at BIGINT NOT NULL,
        updated_at BIGINT NOT NULL,
        is_removed TINYINT NOT NULL DEFAULT 0,
        INDEX broadside_artifacts_project_idx (project_id),
        INDEX broadside_artifacts_task_idx (task_id),
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
    ) ENGINE=InnoDB CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS broadside_versions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        artifact_id INT NOT NULL,
        number INT NOT NULL,
        body MEDIUMTEXT NOT NULL,
        creator_id INT NOT NULL,
        created_at BIGINT NOT NULL,
        UNIQUE INDEX broadside_versions_artifact_number_idx (artifact_id, number),
        FOREIGN KEY (artifact_id) REFERENCES broadside_artifacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS broadside_comments (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        artifact_id INT NOT NULL,
        version INT NOT NULL,
        parent_id INT NULL,
        user_id INT NOT NULL,
        body MEDIUMTEXT NOT NULL,
        quote MEDIUMTEXT NULL,
        created_at BIGINT NOT NULL,
        INDEX broadside_comments_artifact_idx (artifact_id),
        FOREIGN KEY (artifact_id) REFERENCES broadside_artifacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS broadside_submissions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        artifact_id INT NOT NULL,
        version INT NOT NULL,
        form_key VARCHAR(64) NOT NULL,
        user_id INT NOT NULL,
        payload MEDIUMTEXT NOT NULL,
        created_at BIGINT NOT NULL,
        INDEX broadside_submissions_artifact_idx (artifact_id),
        FOREIGN KEY (artifact_id) REFERENCES broadside_artifacts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB CHARSET=utf8mb4');
}
