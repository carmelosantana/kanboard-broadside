<?php $projectParams = ['plugin' => 'Broadside', 'project_id' => $project['id']] ?>
<?php if (isset($list)): ?>
    <?= $this->url->link(t('Upload artifact'), 'ArtifactController', 'upload', $projectParams, false, 'btn') ?>
    <?= $this->render('Broadside:artifact/list', $list) ?>
<?php else: ?>
    <div class="page-header">
        <h2><?= t('Upload artifact') ?></h2>
    </div>

    <?php if (isset($error)): ?>
        <p class="alert alert-error" role="alert"><?= $this->text->e($error) ?></p>
    <?php endif ?>

    <form action="<?= $this->url->href('ArtifactController', 'upload', $projectParams) ?>"
          method="post" enctype="multipart/form-data">
        <?= $this->form->csrf() ?>
        <label for="form-broadside-title"><?= t('Title') ?></label>
        <input type="text" name="title" id="form-broadside-title" required
               value="<?= $this->text->e($artifactTitle ?? '') ?>">
        <label for="form-broadside-artifact"><?= t('HTML or Markdown file') ?></label>
        <input type="file" name="artifact" id="form-broadside-artifact" accept=".html,.md" required>
        <div class="form-actions">
            <button type="submit" class="btn btn-blue"><?= t('Upload') ?></button>
            <?= $this->url->link(t('Cancel'), 'ArtifactController', 'index', $projectParams) ?>
        </div>
    </form>
<?php endif ?>
