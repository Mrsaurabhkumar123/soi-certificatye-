<?php
declare(strict_types=1);

use SOI\Certificates\Core\Session;

/**
 * Reusable Template Home / Library & BasketHunt Reuse Component
 * Fulfills Section 4 of the Development Updates specification:
 * - Category navigation (Certificates, Slides, Portfolio, BasketHunt Applications)
 * - Live search and status filtering
 * - Reusable preview cards with visual badges and actions
 * - Template cloning, archiving, and Canva fallback workflow guidance
 *
 * @var array<\SOI\Certificates\Templates\Template> $templates
 * @var array<string> $categories
 * @var \SOI\Certificates\Tenancy\Tenant $tenant
 * @var \SOI\Certificates\Core\Plugin $this->plugin
 */
$categories = $categories ?? ['Certificates', 'Slides / Presentations', 'Portfolio', 'BasketHunt Applications'];
?>
<div class="card template-library-section" id="templates-section">
  <!-- Section Header -->
  <div class="template-library-hero">
    <div class="library-title-group">
      <h2>Reusable Template Library</h2>
      <p>Multi-application template repository for Certificates, Slides, Portfolios, and BasketHunt workflows.</p>
    </div>
    <div>
      <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('create-template-dialog').showModal();">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align: -2px; margin-right: 4px;">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
        New Template
      </button>
    </div>
  </div>

  <!-- Canva Fallback Workflow Banner -->
  <div class="canva-fallback-banner">
    <div class="canva-banner-text">
      <strong>Canva Fallback Integration:</strong> Need rapid prototyping or external layout drafts? You can design assets in Canva, export them, and import layouts/assets directly into the platform's immutable version registry. Platform metadata, RBAC permissions, and version histories remain strictly internal.
    </div>
  </div>

  <!-- Category Navigation Tabs -->
  <div class="category-tabs-container" role="tablist" aria-label="Template categories">
    <button type="button" class="category-tab-btn active" data-category="all" role="tab" aria-selected="true">
      All Templates (<?= count($templates) ?>)
    </button>
    <?php foreach ($categories as $cat): ?>
      <?php 
        $count = count(array_filter($templates, fn($t) => strcasecmp((string)$t->category, $cat) === 0));
      ?>
      <button type="button" class="category-tab-btn" data-category="<?= htmlspecialchars($cat) ?>" role="tab" aria-selected="false">
        <?= htmlspecialchars($cat) ?> (<?= $count ?>)
      </button>
    <?php endforeach; ?>
  </div>

  <!-- Search & Filter Controls -->
  <div class="template-filters-bar">
    <div class="search-input-wrapper">
      <svg class="search-icon-svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
      </svg>
      <input type="text" id="template-search-box" class="template-search-input" placeholder="Search templates by title, slug, or tags...">
    </div>

    <div>
      <select id="template-status-filter" class="filter-select" aria-label="Filter by lifecycle status">
        <option value="all">All Statuses</option>
        <option value="published">Published</option>
        <option value="draft">Drafts</option>
        <option value="archived">Archived</option>
        <option value="superseded">Superseded</option>
      </select>
    </div>
  </div>

  <!-- Template Cards Grid -->
  <div class="template-cards-grid" id="template-cards-container">
    <?php if (empty($templates)): ?>
      <div style="grid-column: 1 / -1; text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
        <p style="font-size: 1.1rem; margin-bottom: 0.5rem;">No templates found in this workspace.</p>
        <p style="font-size: 0.85rem;">Click "+ New Template" to create a reusable template for Certificates, Slides, or BasketHunt.</p>
      </div>
    <?php else: ?>
      <?php foreach ($templates as $tpl): ?>
        <?php 
          $tplCategory = $tpl->category ?: 'Certificates';
          $statusClass = match($tpl->status) {
            'published' => 'status-published',
            'draft' => 'status-draft',
            'archived' => 'status-archived',
            'superseded' => 'status-superseded',
            default => 'status-draft'
          };
        ?>
        <div class="template-preview-card" 
             data-category="<?= htmlspecialchars($tplCategory) ?>" 
             data-status="<?= htmlspecialchars($tpl->status) ?>"
             data-title="<?= htmlspecialchars(strtolower($tpl->name)) ?>"
             data-slug="<?= htmlspecialchars(strtolower($tpl->slug)) ?>">
          
          <!-- Mockup Thumbnail Preview -->
          <div class="card-preview-thumb">
            <div class="thumb-doc-mock">
              <div class="mock-line-gold"></div>
              <div class="mock-line-title"></div>
              <div class="mock-line-sub"></div>
              <div class="mock-line-sub" style="width: 60%;"></div>
              <div class="mock-seal-circle"></div>
            </div>
          </div>

          <!-- Body Content -->
          <div class="card-body-content">
            <div class="card-tags-row">
              <span class="category-tag-pill"><?= htmlspecialchars($tplCategory) ?></span>
              <span class="status-badge-pill <?= $statusClass ?>">
                <?= htmlspecialchars(ucfirst($tpl->status)) ?>
              </span>
            </div>

            <h3 class="template-card-title"><?= htmlspecialchars($tpl->name) ?></h3>
            <div class="template-card-slug"><?= htmlspecialchars($tpl->slug) ?></div>

            <div class="template-card-meta">
              <span>Created <?= htmlspecialchars(substr($tpl->createdAt, 0, 10)) ?></span>
              <?php if ($tpl->isPublished()): ?>
                <span style="color: var(--success); font-weight: 600; margin-left: 0.5rem;">• Active for Issuance</span>
              <?php endif; ?>
            </div>

            <!-- Actions Toolbar -->
            <div class="template-card-actions">
              <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/' . $tpl->id . '/designer')) ?>">
                <?= $tpl->isPublished() ? 'Edit as Draft' : 'Open Designer' ?>
              </a>

              <?php if (!$tpl->isPublished() && $tpl->status !== 'archived'): ?>
                <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/publish')) ?>" style="display:inline;">
                  <?= Session::csrfField() ?>
                  <input type="hidden" name="template_id" value="<?= (int)$tpl->id ?>">
                  <button type="submit" class="btn btn-primary btn-sm" title="Publish as immutable release version">Publish</button>
                </form>
              <?php endif; ?>

              <!-- Clone Button -->
              <button type="button" class="btn btn-outline btn-sm clone-template-btn" 
                      data-id="<?= (int)$tpl->id ?>" 
                      data-name="<?= htmlspecialchars($tpl->name) ?>" 
                      data-category="<?= htmlspecialchars($tplCategory) ?>">
                Clone
              </button>

              <?php if ($tpl->status !== 'archived'): ?>
                <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/archive')) ?>" style="display:inline;" onsubmit="return confirm('Archive this template? It will be removed from issuance pickers.');">
                  <?= Session::csrfField() ?>
                  <input type="hidden" name="template_id" value="<?= (int)$tpl->id ?>">
                  <button type="submit" class="btn btn-outline btn-sm" style="color: var(--text-muted);">Archive</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Modal: Create New Reusable Template -->
<dialog id="create-template-dialog" class="modal" aria-labelledby="create-tpl-title">
  <div class="modal-content" style="max-width: 540px; padding: 1.5rem; border-radius: 8px; background: #ffffff; border: 1px solid var(--border);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h3 id="create-tpl-title" style="margin: 0; font-size: 1.2rem; font-weight: 700;">Create Reusable Template</h3>
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('create-template-dialog').close();">&times;</button>
    </div>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/create')) ?>">
      <?= Session::csrfField() ?>
      <div class="form-group">
        <label class="form-label" for="tpl_name">Template Title</label>
        <input id="tpl_name" name="name" class="form-control" placeholder="e.g. BasketHunt Leadership Award or Project Slide" required maxlength="128">
      </div>
      <div class="form-group">
        <label class="form-label" for="tpl_slug">URL Slug Identifier</label>
        <input id="tpl_slug" name="slug" class="form-control" placeholder="e.g. baskethunt-leadership-award" required maxlength="64" pattern="^[a-z0-9][a-z0-9-]{0,63}$">
      </div>
      <div class="form-group">
        <label class="form-label" for="tpl_category_select">Template Domain / Category</label>
        <select id="tpl_category_select" name="category" class="form-control">
          <option value="Certificates">Certificates</option>
          <option value="Slides / Presentations">Slides / Presentations</option>
          <option value="Portfolio">Portfolio Templates</option>
          <option value="BasketHunt Applications">BasketHunt Applications</option>
          <option value="General Documents">General Documents</option>
        </select>
        <small style="color: var(--text-muted); font-size: 0.78rem;">Data-driven categories enable cross-application reuse across all BasketHunt domains.</small>
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('create-template-dialog').close();">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Create Template Draft</button>
      </div>
    </form>
  </div>
</dialog>

<!-- Modal: Clone Template -->
<dialog id="clone-template-dialog" class="modal" aria-labelledby="clone-tpl-title">
  <div class="modal-content" style="max-width: 520px; padding: 1.5rem; border-radius: 8px; background: #ffffff; border: 1px solid var(--border);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h3 id="clone-tpl-title" style="margin: 0; font-size: 1.2rem; font-weight: 700;">Clone Template</h3>
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('clone-template-dialog').close();">&times;</button>
    </div>
    <form method="POST" action="<?= htmlspecialchars($this->plugin->router->url('/manage/templates/clone')) ?>">
      <?= Session::csrfField() ?>
      <input type="hidden" name="template_id" id="clone-source-id">
      <div class="form-group">
        <label class="form-label" for="clone_name">New Template Name</label>
        <input id="clone_name" name="name" class="form-control" required maxlength="128">
      </div>
      <div class="form-group">
        <label class="form-label" for="clone_slug">New URL Slug</label>
        <input id="clone_slug" name="slug" class="form-control" required maxlength="64" pattern="^[a-z0-9][a-z0-9-]{0,63}$">
      </div>
      <div class="form-group">
        <label class="form-label" for="clone_category">Category</label>
        <input id="clone_category" name="category" class="form-control" maxlength="64">
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
        <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('clone-template-dialog').close();">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Clone as Draft</button>
      </div>
    </form>
  </div>
</dialog>

<!-- Live Search and Category Filter Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
  var searchInput = document.getElementById('template-search-box');
  var statusFilter = document.getElementById('template-status-filter');
  var tabButtons = document.querySelectorAll('.category-tab-btn');
  var cards = document.querySelectorAll('.template-preview-card');
  var activeCategory = 'all';

  function filterCards() {
    var query = (searchInput ? searchInput.value : '').toLowerCase().trim();
    var status = statusFilter ? statusFilter.value : 'all';

    cards.forEach(function(card) {
      var cardCat = (card.getAttribute('data-category') || '').toLowerCase();
      var cardStatus = (card.getAttribute('data-status') || '').toLowerCase();
      var cardTitle = card.getAttribute('data-title') || '';
      var cardSlug = card.getAttribute('data-slug') || '';

      var matchesCategory = (activeCategory === 'all') || (cardCat === activeCategory.toLowerCase());
      var matchesStatus = (status === 'all') || (cardStatus === status);
      var matchesQuery = !query || (cardTitle.indexOf(query) !== -1) || (cardSlug.indexOf(query) !== -1);

      if (matchesCategory && matchesStatus && matchesQuery) {
        card.style.display = '';
      } else {
        card.style.display = 'none';
      }
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', filterCards);
  }
  if (statusFilter) {
    statusFilter.addEventListener('change', filterCards);
  }

  tabButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      tabButtons.forEach(function(b) { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');
      activeCategory = btn.getAttribute('data-category') || 'all';
      filterCards();
    });
  });

  // Setup clone modal handlers
  var cloneDialog = document.getElementById('clone-template-dialog');
  var cloneSourceId = document.getElementById('clone-source-id');
  var cloneName = document.getElementById('clone_name');
  var cloneSlug = document.getElementById('clone_slug');
  var cloneCategory = document.getElementById('clone_category');

  document.querySelectorAll('.clone-template-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var id = btn.getAttribute('data-id');
      var name = btn.getAttribute('data-name') || '';
      var cat = btn.getAttribute('data-category') || '';
      if (cloneSourceId) cloneSourceId.value = id;
      if (cloneName) cloneName.value = name + ' (Clone)';
      if (cloneSlug) cloneSlug.value = (name.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-copy').substring(0, 50);
      if (cloneCategory) cloneCategory.value = cat;
      if (cloneDialog) cloneDialog.showModal();
    });
  });
});
</script>
