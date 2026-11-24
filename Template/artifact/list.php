<div class="page-header">
    <h2><?= t('Artifacts') ?></h2>
</div>

<div class="broadside-toolbar">
    <form method="get" role="search" class="broadside-search">
        <input type="hidden" name="controller" value="ArtifactController">
        <input type="hidden" name="action" value="index">
        <input type="hidden" name="plugin" value="Broadside">
        <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
        <input type="search" name="search" aria-label="<?= t('Search artifact titles') ?>"
               placeholder="<?= t('Search artifact titles') ?>" value="<?= $this->text->e($search) ?>">
        <button type="submit" class="btn"><?= t('Search') ?></button>
    </form>
    <a href="#broadside-publishing" class="btn btn-blue"><?= t('Publishing instructions') ?></a>
</div>

<?php if (! $hasArtifacts): ?>
    <?= $this->render('Broadside:artifact/empty') ?>
<?php elseif ($artifacts === []): ?>
    <p><?= t('No artifacts match your search.') ?></p>
<?php else: ?>
    <table class="table-striped">
        <thead>
            <tr>
                <th scope="col"><?= t('Title') ?></th>
                <th scope="col"><?= t('Type') ?></th>
                <th scope="col"><?= t('Publisher') ?></th>
                <th scope="col"><?= t('Version') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($artifacts as $artifact): ?>
                <tr>
                    <td><?= $this->url->link($this->text->e($artifact['title']), 'ArtifactController', 'show', [
                        'plugin' => 'Broadside', 'artifact_id' => $artifact['id'],
                    ]) ?></td>
                    <td><?= $artifact['type'] === 'html' ? t('HTML') : t('Markdown') ?></td>
                    <td><?= $this->text->e($publishers[$artifact['creator_id']] ?? t('Unknown publisher')) ?></td>
                    <td><?= (int) $artifact['current_version'] ?></td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>

<section id="broadside-publishing" tabindex="-1">
    <h3><?= t('Publish with an agent') ?></h3>
    <p><?= e('Ask an agent with project member access to read %s, then call %s with this project’s ID (%d), a title and the HTML or Markdown body.',
        '<code>artifactGetContract</code>', '<code>artifactPublish</code>', $project['id']) ?></p>
</section>
