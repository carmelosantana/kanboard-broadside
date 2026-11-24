<?php

namespace Kanboard\Plugin\Broadside\Api;

use Kanboard\Plugin\Broadside\Model\BroadsideProcedureModel;
use Kanboard\Plugin\Broadside\Model\CommentModel;
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

    public function artifactImport($project_id, $title, $content, $format = 'html', $source_url = '', $artifact_id = 0): array
    {
        return $this->model->import($project_id, $title, $content, $format, $source_url, $artifact_id);
    }

    public function artifactList($project_id): array
    {
        return $this->model->list($project_id);
    }

    public function artifactGetContract(): string
    {
        return $this->model->getContract();
    }

    public function artifactCommentList($project_id, $since_id = 0, $limit = CommentModel::DEFAULT_LIMIT, $artifact_id = 0): array
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
