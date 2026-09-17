# Notification Component

## HTML/PHP

```php
<button type="button"
        class="util-btn notif-trigger"
        data-notif-trigger
        aria-haspopup="true"
        aria-expanded="false"
        aria-controls="notif-flyout"
        aria-label="View notifications<?= $bellCount ? ' - ' . $bellCount . ' unread' : '' ?>">
    <?= icon('bell', 20) ?>
    <span class="notif-badge<?= $bellCount ? '' : ' is-empty' ?>"
          data-notif-badge
          data-unread-count="<?= (int)$bellCount ?>"
          aria-hidden="true"></span>
</button>
```

## SVG

The shared `icon('bell', 20)` helper renders the bell using `currentColor` so the icon follows the active theme without a second SVG asset:

```html
<svg class="icon icon-bell" width="20" height="20" viewBox="0 0 24 24"
     fill="none" stroke="currentColor" stroke-width="1.8"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <path d="M6 10a6 6 0 0 1 12 0c0 3.7.6 5.3 2 6.3.3.2.4.5.3.7-.1.3-.4.5-.7.5H4.4c-.3 0-.6-.2-.7-.5-.1-.2 0-.5.3-.7 1.4-1 2-2.6 2-6.3z"/>
  <path d="M9.5 20a2.5 2.5 0 0 0 5 0"/>
</svg>
```

## CSS

```css
.notif-rail { position: relative; flex: none; }
.notif-trigger { position: relative; }

.notif-badge {
  position: absolute;
  top: 3px;
  right: 3px;
  width: 10px;
  height: 10px;
  padding: 0;
  border-radius: 50%;
  background: #EF4444;
  border: 2px solid var(--bg-surface);
  box-sizing: border-box;
  filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.15));
}

.notif-badge.is-empty { display: none; }

.notif-trigger:hover { background: rgba(15, 23, 42, 0.05); }
body.dark .notif-trigger:hover { background: rgba(255, 255, 255, 0.1); }

.notif-trigger .icon-bell {
  transition: transform 0.3s cubic-bezier(.34, 1.56, .64, 1);
  transform-origin: 50% 20%;
}

.notif-trigger:hover .icon-bell { transform: rotate(15deg); }
.notif-trigger .icon-bell.is-ringing { animation: bell-ring 0.6s ease; }

@keyframes bell-ring {
  0%, 100% { transform: rotate(0); }
  20% { transform: rotate(14deg); }
  40% { transform: rotate(-10deg); }
  60% { transform: rotate(6deg); }
  80% { transform: rotate(-3deg); }
}

@media (prefers-reduced-motion: reduce) {
  .notif-trigger .icon-bell { transition: none; }
  .notif-trigger:hover .icon-bell { transform: none; }
  .notif-trigger .icon-bell.is-ringing { animation: none; }
}
```

## JavaScript behavior

The visible badge is now a dot, so the unread count is stored in `data-unread-count` rather than parsed from visible text. The count remains available to assistive technology through the button's `aria-label` and continues to drive the notification flyout's summary.
