<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? '';
$currentPage = $currentPage ?? '';
$coachHelperContext = $coachHelperContext ?? [];

$page = (string)($coachHelperContext['page'] ?? $currentPage ?: 'general');
$pageTitleValue = (string)($coachHelperContext['page_title'] ?? $pageTitle ?: '');
$gameIdValue = (int)($coachHelperContext['game_id'] ?? $gameId ?? 0);

$quickActions = function_exists('coach_helper_quick_actions')
    ? coach_helper_quick_actions($page)
    : ['What can you help with?'];

$userId = current_user_id();

$latestFeature = get_latest_unseen_feature_for_user($userId);
$shouldNudgeFeatures = $latestFeature !== null && $currentPage !== 'features';
?>

<?php if (!empty($shouldNudgeFeatures) && $latestFeature): ?>
  <div
    id="coach-helper-feature-nudge"
    class="coach-helper-feature-nudge no-print"
    data-feature-id="<?= (int)$latestFeature['id'] ?>"
    data-title="<?= h((string)$latestFeature['title']) ?>"
    data-summary="<?= h((string)$latestFeature['summary']) ?>"
    data-url="features.php"
  >
    <div class="nudge-content">
      <strong>New:</strong> <?= h((string)$latestFeature['title']) ?>
    </div>
    <button class="nudge-close" type="button">×</button>
  </div>
<?php endif; ?>

<div id="coach-helper-fab" class="coach-helper-fab no-print">
  Coach Helper
</div>

<div id="coach-helper-widget" class="coach-helper-widget">
  <div class="coach-helper-drag-handle-wrap">
    <div class="coach-helper-drag-handle"></div>
  </div>

  <div class="coach-helper-header">
    <div class="coach-helper-title">Coach Helper</div>
    <button type="button" id="coach-helper-close" class="coach-helper-close">×</button>
  </div>

  <div id="coach-helper-messages" class="coach-helper-messages">
    <div class="coach-helper-bot">
      Ask for help with this page, players, games, lineup warnings, fairness, suggestions, or settings.
    </div>
  </div>

  <div class="coach-helper-quick-actions">
    <?php foreach ($quickActions as $quickAction): ?>
      <button
        type="button"
        class="btn btn-secondary coach-helper-quick"
        data-message="<?= h((string)$quickAction) ?>"
      >
        <?= h((string)$quickAction) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <form id="coach-helper-form" class="coach-helper-form">
      <?= csrf_field() ?>
    <input type="hidden" id="coach-helper-game-id" value="<?= (int)$gameIdValue ?>">
    <input type="hidden" id="coach-helper-page" value="<?= h($page) ?>">
    <input type="hidden" id="coach-helper-page-title" value="<?= h($pageTitleValue) ?>">

    <input
      type="text"
      id="coach-helper-input"
      class="coach-helper-input"
      placeholder="Ask Coach Helper..."
      autocomplete="off"
    >

    <button type="submit" class="btn">Send</button>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const fab = document.getElementById('coach-helper-fab');
  const widget = document.getElementById('coach-helper-widget');
  const closeBtn = document.getElementById('coach-helper-close');
  const form = document.getElementById('coach-helper-form');
  const input = document.getElementById('coach-helper-input');
  const messages = document.getElementById('coach-helper-messages');
  const gameIdInput = document.getElementById('coach-helper-game-id');
  const pageInput = document.getElementById('coach-helper-page');
  const quickButtons = document.querySelectorAll('.coach-helper-quick');
  const dragHandle = widget ? widget.querySelector('.coach-helper-drag-handle') : null;
  const nudge = document.getElementById('coach-helper-feature-nudge');

  if (!fab || !widget) {
    return;
  }

  function openWidget() {
    widget.classList.add('is-open');
    if (input) {
      input.focus();
    }
  }

  function closeWidget() {
    widget.classList.remove('is-open');
    widget.style.transform = '';
    widget.style.transition = '';
  }

  function appendMessage(text, type, actionUrl = null, actionLabel = null) {
    if (!messages) return;

    const div = document.createElement('div');
    div.className = type === 'user' ? 'coach-helper-user' : 'coach-helper-bot';

    const textDiv = document.createElement('div');
    textDiv.textContent = text;
    div.appendChild(textDiv);

    if (type === 'bot' && actionUrl && actionLabel) {
      const link = document.createElement('a');
      link.href = actionUrl;
      link.textContent = actionLabel;
      link.className = 'coach-helper-action-link';
      div.appendChild(link);
    }

    messages.appendChild(div);
    messages.scrollTop = messages.scrollHeight;
  }

  fab.addEventListener('click', function (e) {
    e.preventDefault();
    e.stopPropagation();

    if (widget.classList.contains('is-open')) {
      closeWidget();
    } else {
      openWidget();
    }
  });

  if (closeBtn) {
    closeBtn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      closeWidget();
    });
  }

  let startY = 0;
  let currentY = 0;
  let isDragging = false;

  function setTranslate(y) {
    widget.style.transform = 'translateY(' + y + 'px)';
  }

  function resetTranslate() {
    widget.style.transform = '';
  }

  if (dragHandle) {
    dragHandle.addEventListener('touchstart', function (e) {
      if (!widget.classList.contains('is-open')) return;

      isDragging = true;
      startY = e.touches[0].clientY;
      currentY = 0;
      widget.style.transition = 'none';
    }, { passive: true });

    dragHandle.addEventListener('touchmove', function (e) {
      if (!isDragging) return;

      const y = e.touches[0].clientY;
      currentY = Math.max(0, y - startY);
      setTranslate(currentY);
    }, { passive: true });

    dragHandle.addEventListener('touchend', function () {
      if (!isDragging) return;

      isDragging = false;
      widget.style.transition = '';

      if (currentY > 120) {
        closeWidget();
      } else {
        resetTranslate();
      }
    });

    dragHandle.addEventListener('mousedown', function (e) {
      if (!widget.classList.contains('is-open')) return;

      isDragging = true;
      startY = e.clientY;
      currentY = 0;
      widget.style.transition = 'none';
      e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
      if (!isDragging) return;

      currentY = Math.max(0, e.clientY - startY);
      setTranslate(currentY);
    });

    document.addEventListener('mouseup', function () {
      if (!isDragging) return;

      isDragging = false;
      widget.style.transition = '';

      if (currentY > 120) {
        closeWidget();
      } else {
        resetTranslate();
      }
    });
  }

  async function sendCoachMessage(text) {
    if (!form || !messages) return;

    const trimmed = (text || '').trim();
    if (!trimmed) return;

    openWidget();
    appendMessage(trimmed, 'user');

    if (input) {
      input.value = '';
    }

    const formData = new FormData();
    formData.append('game_id', gameIdInput ? gameIdInput.value : '');
    formData.append('page', pageInput ? pageInput.value : '');
    formData.append('message', trimmed);

    try {
      const response = await fetch('coach_helper.php', {
        method: 'POST',
        body: formData
      });

      const data = await response.json();

      appendMessage(
        data.reply || 'No response returned.',
        'bot',
        data.action_url || null,
        data.action_label || null
      );
    } catch (error) {
      appendMessage('Coach Helper could not respond right now.', 'bot');
    }
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      sendCoachMessage(input ? input.value : '');
    });
  }

  quickButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      sendCoachMessage(btn.dataset.message || '');
    });
  });

  function triggerWiggle(el) {
    el.classList.remove('wiggle');
    void el.offsetWidth;
    el.classList.add('wiggle');

    setTimeout(function () {
      el.classList.remove('wiggle');
    }, 700);
  }

  if (nudge) {
    const featureId = nudge.dataset.featureId || '';
    const dismissedFeatureId = localStorage.getItem('coach_helper_dismissed_feature_id');

    if (dismissedFeatureId && dismissedFeatureId === featureId) {
      nudge.remove();
    } else {
      const nudgeCloseBtn = nudge.querySelector('.nudge-close');

      if (nudgeCloseBtn) {
        nudgeCloseBtn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          localStorage.setItem('coach_helper_dismissed_feature_id', featureId);
          nudge.remove();
        });
      }

      nudge.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();

        const title = nudge.dataset.title || '';
        const summary = nudge.dataset.summary || '';
        const url = nudge.dataset.url || 'features.php';

        openWidget();

        if (messages) {
          const existingIntro = messages.querySelector('.coach-helper-feature-announcement');

          if (!existingIntro) {
            const intro = document.createElement('div');
            intro.className = 'coach-helper-bot coach-helper-feature-announcement';
            intro.textContent = 'What’s new: ' + title;
            messages.appendChild(intro);

            if (summary !== '') {
              const detail = document.createElement('div');
              detail.className = 'coach-helper-bot coach-helper-feature-announcement';
              detail.textContent = summary;
              messages.appendChild(detail);
            }

            const cta = document.createElement('div');
            cta.className = 'coach-helper-bot coach-helper-feature-announcement';

            const link = document.createElement('a');
            link.href = url;
            link.className = 'coach-helper-action-link';
            link.textContent = 'See What’s New';

            cta.appendChild(link);
            messages.appendChild(cta);

            messages.scrollTop = messages.scrollHeight;
          }
        }

        nudge.remove();
      });

      const wiggleLoop = function () {
        if (!document.body.contains(nudge)) return;

        triggerWiggle(nudge);

        const nextDelay = 10000 + Math.floor(Math.random() * 5000);
        setTimeout(wiggleLoop, nextDelay);
      };

      const firstDelay = 10000 + Math.floor(Math.random() * 5000);
      setTimeout(wiggleLoop, firstDelay);
    }
  }
});
</script>
