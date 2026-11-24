<?php foreach ($linkedArtifacts as $artifact): ?>
    <li>
        <?= $this->url->icon('file-text-o', $this->text->e($artifact['title']), 'ArtifactController', 'show', [
            'plugin' => 'Broadside', 'artifact_id' => $artifact['id'],
        ]) ?>
    </li>
<?php endforeach ?>
