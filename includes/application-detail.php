<?php
/**
 * "What you submitted" panel for one application.
 *
 * Expects $detail (from application_submission_payload()) and $lookupEmail in
 * scope. Included twice: once by application-status.php when a panel is opened
 * without JavaScript, and once by its own ?action=detail endpoint, which the
 * accordion fetches lazily. Keeping it in one file is what stops the two paths
 * from drifting apart.
 */
$answers = array_values(array_filter($detail['answers'], fn($a) => trim((string)$a['value']) !== ''));
?>
<div class="cs-detail-inner">
  <div class="cs-detail-block">
    <h4 class="cs-detail-title">Your answers</h4>
    <dl class="cs-answers">
      <?php foreach ($answers as $a): ?>
        <div class="cs-answer<?= $a['long'] ? ' is-long' : '' ?>">
          <dt><?= e($a['label']) ?></dt>
          <dd>
            <?php if (!empty($a['url'])): ?>
              <a href="<?= e($a['value']) ?>" target="_blank" rel="noopener nofollow"><?= e($a['value']) ?></a>
            <?php elseif ($a['long']): ?>
              <?= nl2br(e($a['value'])) ?>
            <?php else: ?>
              <?= e($a['value']) ?>
            <?php endif; ?>
          </dd>
        </div>
      <?php endforeach; ?>
    </dl>
  </div>

  <div class="cs-detail-block">
    <h4 class="cs-detail-title">Your documents</h4>
    <?php if ($detail['documents']): ?>
      <ul class="cs-files">
        <?php foreach ($detail['documents'] as $doc):
          $href = 'my-document.php?file_id=' . (int)$doc['id']
                . '&id=' . (int)$detail['id']
                . '&email=' . urlencode($lookupEmail);
        ?>
          <li class="cs-file">
            <span class="cs-file-icon" aria-hidden="true"><?= e(strtoupper($doc['extension'])) ?></span>
            <span class="cs-file-meta">
              <span class="cs-file-name"><?= e($doc['original_name']) ?></span>
              <span class="cs-file-sub">
                <?= e(format_bytes((int)$doc['byte_size'])) ?>
                · Uploaded <?= e(date('M j, Y', strtotime($doc['created_at']))) ?>
                <?= $doc['is_primary'] ? ' · Current resume' : '' ?>
              </span>
            </span>
            <span class="cs-file-actions">
              <?php if ($doc['extension'] === 'pdf'): ?>
                <a class="btn small secondary" target="_blank" rel="noopener"
                   href="<?= e($href . '&disposition=inline') ?>"
                   aria-label="View <?= e($doc['original_name']) ?> in a new tab">View</a>
              <?php endif; ?>
              <a class="btn small ghost" href="<?= e($href) ?>"
                 aria-label="Download <?= e($doc['original_name']) ?>">Download</a>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php elseif ($detail['legacy_resume']): ?>
      <ul class="cs-files">
        <li class="cs-file">
          <span class="cs-file-icon" aria-hidden="true">DOC</span>
          <span class="cs-file-meta">
            <span class="cs-file-name">Resume</span>
            <span class="cs-file-sub">Attached with your application</span>
          </span>
          <span class="cs-file-actions">
            <a class="btn small ghost" href="<?= e($detail['legacy_resume']) ?>" target="_blank" rel="noopener">Open</a>
          </span>
        </li>
      </ul>
    <?php else: ?>
      <p class="cs-empty-line">No documents are attached to this application.</p>
    <?php endif; ?>
  </div>
</div>
