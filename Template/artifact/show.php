<?php
$artifactParams = ['plugin' => 'Broadside', 'artifact_id' => $artifact['id']];
$viewerUrl = $this->url->to('ArtifactController', 'show', $artifactParams);
$versionSeparator = str_contains($viewerUrl, '?') ? '&' : '?';
?>
<div class="broadside-viewer" data-csrf="<?= $this->text->e($csrf) ?>">
    <div class="page-header">
        <?= $this->url->link(t('Back to Artifacts'), 'ArtifactController', 'index', [
            'plugin' => 'Broadside', 'project_id' => $project['id'],
        ]) ?>
        <h2><?= $this->text->e($artifact['title']) ?></h2>
    </div>
    <details class="broadside-versions">
        <summary><?= t('Version %d', $version) ?></summary>
        <ul>
            <?php foreach ($versions as $number): ?>
                <li>
                    <a href="<?= $this->text->e($viewerUrl.$versionSeparator.'version='.$number) ?>"
                        <?= $number === $version ? 'aria-current="page"' : '' ?>><?= t('Version %d', $number) ?></a>
                </li>
            <?php endforeach ?>
        </ul>
    </details>
    <?php if ($canShare): ?>
        <form method="post" action="<?= $this->text->e($this->url->to('ShareController', 'toggle', $artifactParams)) ?>">
            <?= $this->form->csrf() ?>
            <?php if ($shareUrl !== null): ?>
                <a href="<?= $this->text->e($shareUrl) ?>" rel="noreferrer"><?= t('Public link') ?></a>
            <?php endif ?>
            <button type="submit" class="btn" name="enabled" value="<?= $shareUrl === null ? '1' : '0' ?>">
                <?= $shareUrl === null ? t('Create public link') : t('Revoke public link') ?>
            </button>
        </form>
    <?php endif ?>
    <?php // Load the listener before the iframe can send its ready message. ?>
    <script src="<?= $this->text->e($this->url->dir().'plugins/Broadside/Assets/js/viewer.js?'.filemtime(__DIR__.'/../../Assets/js/viewer.js')) ?>"></script>
    <iframe class="broadside-frame" id="broadside-frame"
            sandbox="allow-scripts allow-popups allow-forms allow-modals"
            src="<?= $this->text->e($rawUrl) ?>" title="<?= $this->text->e($artifact['title']) ?>"
            data-submit-url="<?= $this->text->e($this->url->to('BridgeController', 'submit', $artifactParams)) ?>"
            data-results-url="<?= $this->text->e($this->url->to('BridgeController', 'results', $artifactParams)) ?>"
            data-version="<?= $this->text->e($version) ?>"
            data-theme="<?= $this->text->e($theme) ?>" data-preset="<?= $this->text->e($preset) ?>"></iframe>
</div>
