<div class="sidebar-container">
    <div class="sidebar-content">
        <?= $this->render('Broadside:artifact/show', compact(
            'project', 'artifact', 'version', 'versions', 'rawUrl', 'theme', 'preset', 'csrf'
        )) ?>
    </div>
    <aside class="sidebar" id="broadside-comments" aria-labelledby="broadside-comments-heading" style="padding-inline-start: 1rem; overflow-wrap: anywhere">
        <h3 id="broadside-comments-heading"><?= t('Comments') ?></h3>
        <?php foreach ($comments as $comment): ?>
            <article id="comment-<?= (int) $comment['id'] ?>">
                <p>
                    <strong><?= $this->text->e($comment['username'] ?? t('Deleted user')) ?></strong>
                    · <?= t('Version %d', $comment['version']) ?>
                    · <a href="#comment-<?= (int) $comment['id'] ?>">#<?= (int) $comment['id'] ?></a>
                    <?php if ($comment['parent_id'] !== null): ?>
                        · <a href="#comment-<?= (int) $comment['parent_id'] ?>"><?= t('Reply to #%d', $comment['parent_id']) ?></a>
                    <?php endif ?>
                </p>
                <?php if ($comment['quote'] !== null): ?>
                    <blockquote style="white-space: pre-wrap"><?= $this->text->e($comment['quote']) ?></blockquote>
                <?php endif ?>
                <div class="markdown"><?= $this->text->markdown($comment['body']) ?></div>
            </article>
        <?php endforeach ?>

        <form method="post" action="<?= $this->text->e($this->url->to('ArtifactController', 'comment', [
            'plugin' => 'Broadside', 'artifact_id' => $artifact['id'],
        ])) ?>">
            <?= $this->form->csrf() ?>
            <input type="hidden" name="version" value="<?= (int) $version ?>">
            <?php if ($comments !== []): ?>
                <label for="broadside-comment-parent"><?= t('Reply to') ?></label>
                <select id="broadside-comment-parent" name="parent_id">
                    <option value="0"><?= t('New comment') ?></option>
                    <?php foreach ($comments as $comment): ?>
                        <option value="<?= (int) $comment['id'] ?>">#<?= (int) $comment['id'] ?> · <?= $this->text->e($comment['username'] ?? t('Deleted user')) ?></option>
                    <?php endforeach ?>
                </select>
            <?php endif ?>
            <label for="broadside-comment-body"><?= t('Comment on version %d', $version) ?></label>
            <textarea id="broadside-comment-body" name="body" rows="4" required></textarea>
            <div>
                <label for="broadside-comment-quote"><?= t('Quote (optional)') ?></label>
                <textarea id="broadside-comment-quote" name="quote" rows="2"></textarea>
                <button type="button" id="broadside-quote-selection" hidden><?= t('Quote selected text') ?></button>
            </div>
            <button type="submit" class="btn btn-blue"><?= t('Post comment') ?></button>
        </form>
    </aside>
</div>
