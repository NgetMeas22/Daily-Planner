/**
 * DAILY PLANNER — INTERACTIVE UI SCRIPT
 * Lightweight, zero external dependencies, high performance.
 */

(function () {
  'use strict';

  /* --------------------------------------------------------------------------
     1. Theme Management (Light / Dark Mode with Instant Switch & Sync)
     -------------------------------------------------------------------------- */
  function getActiveTheme() {
    try {
      var stored = localStorage.getItem('dp_theme');
      if (stored === 'dark' || stored === 'light') return stored;
    } catch (e) {}
    var docTheme = document.documentElement.getAttribute('data-theme');
    if (docTheme === 'dark' || docTheme === 'light') return docTheme;
    var bodyTheme = document.body ? document.body.getAttribute('data-theme') : null;
    if (bodyTheme === 'dark' || bodyTheme === 'light') return bodyTheme;
    return 'light';
  }

  function applyTheme(theme) {
    if (theme !== 'dark' && theme !== 'light') theme = 'light';

    document.documentElement.setAttribute('data-theme', theme);
    if (document.body) {
      document.body.setAttribute('data-theme', theme);
    }

    if (theme === 'dark') {
      document.documentElement.setAttribute('data-bs-theme', 'dark');
      if (document.body) document.body.setAttribute('data-bs-theme', 'dark');
    } else {
      document.documentElement.removeAttribute('data-bs-theme');
      if (document.body) document.body.removeAttribute('data-bs-theme');
    }

    try {
      localStorage.setItem('dp_theme', theme);
    } catch (e) {}

    // Cookie fallback for server-side pre-rendering
    document.cookie = 'theme_mode=' + encodeURIComponent(theme) + '; path=/; max-age=31536000; SameSite=Lax';

    // Update segmented theme switch pill buttons
    var lightBtn = document.getElementById('themeLightBtn');
    var darkBtn = document.getElementById('themeDarkBtn');
    if (lightBtn) lightBtn.classList.toggle('active', theme === 'light');
    if (darkBtn) darkBtn.classList.toggle('active', theme === 'dark');

    // Update legacy theme toggle button icons & aria-labels
    document.querySelectorAll('.theme-toggle-btn').forEach(function (btn) {
      btn.setAttribute('aria-label', theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode');
      var indicator = btn.querySelector('.theme-icon-indicator');
      if (indicator) {
        indicator.innerHTML = theme === 'dark'
          ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg> Light'
          : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg> Dark';
      }
    });

    // Notify listeners (e.g. Chart.js redraws)
    window.dispatchEvent(new CustomEvent('dp:themechange', { detail: { theme: theme } }));
  }

  window.setThemeMode = function (mode) {
    if (mode !== 'light' && mode !== 'dark' && mode !== 'system') return;
    var effectiveTheme = mode;
    if (mode === 'system') {
      effectiveTheme = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    applyTheme(effectiveTheme);

    try {
      localStorage.setItem('dp_theme', mode);
    } catch (e) {}

    // Persist to server via async fetch (silent background update)
    var formData = new FormData();
    formData.append('theme_mode', mode);
    fetch('theme_toggle.php', {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'fetch',
        'Accept': 'application/json'
      }
    }).catch(function (err) {
      console.warn('Theme save error:', err);
    });
  };

  var lastThemeToggleTimestamp = 0;
  window.toggleTheme = function (e) {
    if (e && e.preventDefault) e.preventDefault();
    var now = Date.now();
    if (now - lastThemeToggleTimestamp < 300) return;
    lastThemeToggleTimestamp = now;

    var currentTheme = getActiveTheme();
    var nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
    window.setThemeMode(nextTheme);
  };

  /* --------------------------------------------------------------------------
     2. User Navbar Dropdown System (Pic 2 Request: Single Consolidated Dropdown)
     -------------------------------------------------------------------------- */
  function initUserDropdown() {
    var trigger = document.getElementById('userMenuBtn');
    var dropdown = document.getElementById('userMenuDropdown');
    var wrap = document.getElementById('userDropdownWrap');

    if (!trigger || !dropdown) return;

    function openDropdown() {
      dropdown.classList.add('show');
      trigger.setAttribute('aria-expanded', 'true');
    }

    function closeDropdown() {
      dropdown.classList.remove('show');
      trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      if (dropdown.classList.contains('show')) {
        closeDropdown();
      } else {
        openDropdown();
      }
    });

    // Close dropdown on click outside
    document.addEventListener('click', function (e) {
      if (wrap && !wrap.contains(e.target)) {
        closeDropdown();
      }
    });

    // Close dropdown on escape key
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && dropdown.classList.contains('show')) {
        closeDropdown();
        trigger.focus();
      }
    });
  }

  /* --------------------------------------------------------------------------
     3. Search Filter for Lists and Tables
     -------------------------------------------------------------------------- */
  function initSearch() {
    var searchInput = document.getElementById('pageSearchInput');
    if (!searchInput) return;

    var targetSelector = searchInput.getAttribute('data-target') || '.searchable-item';
    var items = document.querySelectorAll(targetSelector);
    var emptyState = document.getElementById('searchEmptyState');

    searchInput.addEventListener('input', function () {
      var query = this.value.trim().toLowerCase();
      var visibleCount = 0;

      items.forEach(function (item) {
        var text = (item.getAttribute('data-search') || item.textContent || '').toLowerCase();
        var match = query === '' || text.indexOf(query) !== -1;
        item.style.display = match ? '' : 'none';
        if (match) visibleCount++;
      });

      if (emptyState) {
        emptyState.style.display = visibleCount === 0 && query !== '' ? 'block' : 'none';
      }
    });
  }

  /* --------------------------------------------------------------------------
     4. Quick Add Form Inline Toggles
     -------------------------------------------------------------------------- */
  function initQuickAdd() {
    var triggers = document.querySelectorAll('[data-toggle="quick-add"]');
    triggers.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var targetId = this.getAttribute('data-target');
        var form = document.getElementById(targetId);
        if (!form) return;

        var isExpanded = this.getAttribute('aria-expanded') === 'true';

        // Close other forms
        document.querySelectorAll('.quick-add-form.show').forEach(function (f) {
          if (f.id !== targetId) {
            f.classList.remove('show');
            var otherBtn = document.querySelector('[data-target="' + f.id + '"]');
            if (otherBtn) otherBtn.setAttribute('aria-expanded', 'false');
          }
        });

        this.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
        form.classList.toggle('show', !isExpanded);

        if (!isExpanded) {
          var firstInput = form.querySelector('input:not([type=hidden]), select, textarea');
          if (firstInput) setTimeout(function () { firstInput.focus(); }, 100);
        }
      });
    });
  }

  /* --------------------------------------------------------------------------
     5. Activity Filter Chips
     -------------------------------------------------------------------------- */
  function initActivityFilters() {
    var chips = document.querySelectorAll('.filter-chip');
    var emptyFiltered = document.getElementById('activityEmptyFiltered');
    if (!chips.length) return;

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        chips.forEach(function (c) { c.classList.remove('active'); });
        this.classList.add('active');

        var filter = this.getAttribute('data-filter');
        var items = document.querySelectorAll('.activity-item');
        var visibleCount = 0;

        items.forEach(function (item) {
          var show = (filter === 'all') || item.classList.contains('type-' + filter);
          item.style.display = show ? '' : 'none';
          if (show) visibleCount++;
        });

        if (emptyFiltered) {
          emptyFiltered.style.display = visibleCount === 0 ? 'block' : 'none';
        }
      });
    });
  }

  /* --------------------------------------------------------------------------
     6. Sidebar Navigation (Expand/Collapse on Daily Planner Icon Click)
     -------------------------------------------------------------------------- */
  function initSidebar() {
    var brandToggle = document.getElementById('sidebarBrandToggle');
    var sidebar = document.getElementById('appSidebar');
    var layout = document.getElementById('appLayout');
    var mobileToggle = document.getElementById('mobileSidebarToggle');
    var backdrop = document.getElementById('sidebarBackdrop');

    // Restore saved state (Desktop only)
    try {
      var saved = localStorage.getItem('dp_sidebar_state');
      if (saved === 'expanded' && window.innerWidth >= 768) {
        document.documentElement.classList.add('sidebar-is-expanded');
        if (sidebar) sidebar.classList.add('expanded');
        if (layout) layout.classList.add('sidebar-expanded');
      }
    } catch (e) {}

    // Mobile drawer toggle
    function openMobileDrawer() {
      if (sidebar) sidebar.classList.add('show');
      if (backdrop) backdrop.classList.add('show');
      document.body.classList.add('sidebar-drawer-open');
    }

    function closeMobileDrawer() {
      if (sidebar) sidebar.classList.remove('show');
      if (backdrop) backdrop.classList.remove('show');
      document.body.classList.remove('sidebar-drawer-open');
    }

    if (mobileToggle) {
      mobileToggle.addEventListener('click', function (e) {
        e.preventDefault();
        if (sidebar && sidebar.classList.contains('show')) {
          closeMobileDrawer();
        } else {
          openMobileDrawer();
        }
      });
    }

    if (backdrop) {
      backdrop.addEventListener('click', closeMobileDrawer);
    }

    // Close mobile drawer on escape
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && sidebar && sidebar.classList.contains('show')) {
        closeMobileDrawer();
      }
    });

    // Close mobile drawer when clicking a navigation link
    if (sidebar) {
      sidebar.querySelectorAll('.rail-nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
          if (window.innerWidth < 768) {
            closeMobileDrawer();
          }
        });
      });
    }

    if (brandToggle) {
      brandToggle.addEventListener('click', function (e) {
        e.preventDefault();
        // On mobile, brand toggle closes drawer
        if (window.innerWidth < 768) {
          closeMobileDrawer();
          return;
        }

        var isExpanded = document.documentElement.classList.contains('sidebar-is-expanded') ||
                         (sidebar && sidebar.classList.contains('expanded'));
        var nextState = !isExpanded;

        document.documentElement.classList.toggle('sidebar-is-expanded', nextState);
        if (sidebar) sidebar.classList.toggle('expanded', nextState);
        if (layout) layout.classList.toggle('sidebar-expanded', nextState);

        try {
          localStorage.setItem('dp_sidebar_state', nextState ? 'expanded' : 'collapsed');
        } catch (e) {}

        // Notify charts or maps to resize smoothly
        setTimeout(function () {
          window.dispatchEvent(new Event('resize'));
        }, 260);
      });
    }
  }

  /* --------------------------------------------------------------------------
     7. Confirmation Modal (replaces native confirm() dialogs)
     Usage: <a data-dp-confirm="Title|Message|Accept label" href="...">
     -------------------------------------------------------------------------- */
  function initConfirmModal() {
    var el = document.getElementById('dpConfirmModal');
    if (!el || typeof bootstrap === 'undefined') return;

    var modal = bootstrap.Modal.getOrCreateInstance(el);
    var titleEl = document.getElementById('dpConfirmTitle');
    var textEl = document.getElementById('dpConfirmText');
    var acceptBtn = document.getElementById('dpConfirmAccept');
    var cancelBtn = document.getElementById('dpConfirmCancel');
    var defaults = {
      title: titleEl ? titleEl.textContent : '',
      text: textEl ? textEl.textContent : '',
      accept: acceptBtn ? acceptBtn.textContent.trim() : 'OK',
      cancel: cancelBtn ? cancelBtn.textContent.trim() : 'Cancel'
    };

    var pendingHref = null;
    var busy = false;

    function reset() {
      busy = false;
      pendingHref = null;
      if (titleEl) titleEl.textContent = defaults.title;
      if (textEl) textEl.textContent = defaults.text;
      if (acceptBtn) {
        acceptBtn.textContent = defaults.accept;
        acceptBtn.classList.remove('is-loading');
      }
      if (cancelBtn) cancelBtn.textContent = defaults.cancel;
    }

    el.addEventListener('hidden.bs.modal', reset);

    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-dp-confirm]');
      if (!trigger) return;

      e.preventDefault();
      if (busy) return;

      var parts = (trigger.getAttribute('data-dp-confirm') || '').split('|');
      var title = (parts[0] || '').trim() || trigger.getAttribute('data-dp-confirm-title');
      var text = (parts[1] || '').trim() || trigger.getAttribute('data-dp-confirm-text');
      var accept = (parts[2] || '').trim() || trigger.getAttribute('data-dp-confirm-accept');

      if (title && titleEl) titleEl.textContent = title;
      if (text && textEl) textEl.textContent = text;
      if (accept && acceptBtn) acceptBtn.textContent = accept;

      pendingHref = trigger.getAttribute('href') || trigger.dataset.dpConfirmHref || null;
      modal.show();
    });

    if (acceptBtn) {
      acceptBtn.addEventListener('click', function () {
        if (busy) return;
        busy = true;
        var label = acceptBtn.textContent.trim();
        acceptBtn.classList.add('is-loading');
        acceptBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true" style="width:13px;height:13px;border-width:2px;vertical-align:middle;"></span>' + label;
        if (pendingHref) {
          window.location.href = pendingHref;
        } else {
          modal.hide();
        }
      });
    }
  }

  /* --------------------------------------------------------------------------
     8. DOM Ready Initialization
     -------------------------------------------------------------------------- */
  function init() {
    // Sync current theme state on load
    applyTheme(getActiveTheme());

    initSidebar();
    initUserDropdown();
    initSearch();
    initQuickAdd();
    initActivityFilters();
    initConfirmModal();

    // Wire up segmented theme mode switch buttons
    var lightBtn = document.getElementById('themeLightBtn');
    var darkBtn = document.getElementById('themeDarkBtn');
    if (lightBtn) {
      lightBtn.addEventListener('click', function (e) {
        e.preventDefault();
        window.setThemeMode('light');
      });
    }
    if (darkBtn) {
      darkBtn.addEventListener('click', function (e) {
        e.preventDefault();
        window.setThemeMode('dark');
      });
    }

    // Bind legacy theme buttons in dropdown and elsewhere cleanly
    document.querySelectorAll('.theme-toggle-btn').forEach(function (btn) {
      btn.removeEventListener('click', window.toggleTheme);
      btn.addEventListener('click', window.toggleTheme);
    });

    initButtonLoading();
  }

  /* --------------------------------------------------------------------------
     9. Universal Button Loading & Top Progress Bar ("Add Loding.... all pages in btn or more")
     -------------------------------------------------------------------------- */
  function initButtonLoading() {
    var loader = document.getElementById('dpPageLoader');

    // Forms submission loading
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !(form instanceof HTMLFormElement)) return;
      if (form.hasAttribute('data-no-loading')) return;

      var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
      if (submitBtn && !submitBtn.classList.contains('is-loading')) {
        var loadingText = submitBtn.getAttribute('data-loading-text') || 'Loading...';
        submitBtn.setAttribute('data-original-html', submitBtn.innerHTML);
        submitBtn.classList.add('is-loading');
        if (submitBtn.tagName === 'INPUT') {
          submitBtn.value = loadingText;
        } else {
          submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="width:14px;height:14px;border-width:2px;vertical-align:middle;"></span> ' + loadingText;
        }
        setTimeout(function () {
          submitBtn.disabled = true;
        }, 10);
      }

      if (loader) {
        loader.classList.add('active');
        loader.style.width = '75%';
      }
    }, true);

    // Reset button if HTML5 validation fails
    document.addEventListener('invalid', function (e) {
      var form = e.target.form;
      if (form) {
        var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (submitBtn && submitBtn.hasAttribute('data-original-html')) {
          submitBtn.innerHTML = submitBtn.getAttribute('data-original-html');
          submitBtn.disabled = false;
          submitBtn.classList.remove('is-loading');
          if (loader) {
            loader.style.width = '0%';
            loader.classList.remove('active');
          }
        }
      }
    }, true);

    // Link clicks show top progress bar
    document.addEventListener('click', function (e) {
      var a = e.target.closest('a');
      if (!a || !a.href || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('onclick') || a.hasAttribute('data-dp-confirm')) return;
      var href = a.getAttribute('href') || '';
      if (href.startsWith('#') || href.startsWith('javascript:')) return;

      try {
        var url = new URL(a.href, window.location.href);
        if (url.origin === window.location.origin && url.pathname.endsWith('.php')) {
          if (loader) {
            loader.classList.add('active');
            loader.style.width = '65%';
          }
        }
      } catch (err) {}
    }, true);

    // Finish page loader on window load
    if (loader) {
      loader.style.width = '100%';
      setTimeout(function () {
        loader.classList.remove('active');
        loader.style.width = '0%';
      }, 250);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
