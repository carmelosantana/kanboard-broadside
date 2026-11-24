<?php if ($canViewArtifacts): ?>
<li <?= $this->app->checkMenuSelection('ArtifactController', '', 'Broadside') ?>>
    <?php $params = ['plugin' => 'Broadside', 'project_id' => $project['id']] ?>
    <?= $showIcon
        ? $this->url->icon('file-text-o', t('Artifacts'), 'ArtifactController', 'index', $params)
        : $this->url->link(t('Artifacts'), 'ArtifactController', 'index', $params) ?>
</li>
<?php endif ?>
