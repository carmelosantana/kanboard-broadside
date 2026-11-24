<?php if ($canViewArtifacts): ?>
<li <?= $this->app->checkMenuSelection('ArtifactController', '', 'Broadside') ?>>
    <?= $this->url->icon('file-text-o', t('Artifacts'), 'ArtifactController', 'index', [
        'plugin' => 'Broadside', 'project_id' => $project['id'],
    ]) ?>
</li>
<?php endif ?>
