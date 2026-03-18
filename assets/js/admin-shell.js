/**
 * Tixomat Admin Shell – Sidebar Navigation & Settings Tab Control
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var sidebar = document.getElementById('tix-shell-sidebar');
        if (!sidebar) return;

        // ═══════════════════════════════════════
        // ORGANIZER URL REWRITING
        // ═══════════════════════════════════════
        if (typeof tixShell !== 'undefined' && tixShell.isOrganizer && tixShell.organizerSlug) {
            // Replace wp-admin URL in browser bar with organizer slug
            var path = window.location.pathname;
            if (path.indexOf('/wp-admin') === 0) {
                var subPath = path.replace(/^\/wp-admin\/?/, '');
                var newUrl = tixShell.homeUrl + tixShell.organizerSlug + '/' + (subPath || '');
                // Preserve query string
                if (window.location.search) newUrl += window.location.search;
                if (window.location.hash) newUrl += window.location.hash;
                try { history.replaceState(null, '', newUrl); } catch(e) {}
            }
        }


        // ═══════════════════════════════════════
        // SETTINGS TAB SWITCHING
        // ═══════════════════════════════════════
        var settingsTabs = sidebar.querySelectorAll('.tix-shell-settings-tab');

        if (settingsTabs.length) {
            // Read hash on page load
            var hash = window.location.hash.replace('#', '');
            if (hash) {
                activateSettingsTab(hash);
            }

            // Click handler for sidebar settings tabs
            settingsTabs.forEach(function(item) {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    var tab = this.dataset.settingsTab;
                    if (tab) {
                        activateSettingsTab(tab);
                        history.replaceState(null, '', '#' + tab);
                    }
                });
            });
        }

        function activateSettingsTab(tabName) {
            // Click the hidden nav tab in settings page (reuses existing JS logic)
            var navTab = document.querySelector('.tix-nav-tab[data-tab="' + tabName + '"]');
            if (navTab) {
                navTab.click();
            }

            // Update sidebar active state
            settingsTabs.forEach(function(item) {
                item.classList.toggle('active', item.dataset.settingsTab === tabName);
            });
        }


        // ═══════════════════════════════════════
        // "MEHR" TOGGLE (Settings sub-items)
        // ═══════════════════════════════════════
        var moreBtn   = document.getElementById('tix-shell-settings-more-btn');
        var moreItems = document.getElementById('tix-shell-settings-more');

        if (moreBtn && moreItems) {
            // Restore state
            var moreOpen = localStorage.getItem('tix_shell_settings_more') === '1';
            if (moreOpen) {
                moreItems.classList.add('open');
                moreBtn.classList.add('open');
            }

            moreBtn.addEventListener('click', function() {
                var isOpen = moreItems.classList.toggle('open');
                moreBtn.classList.toggle('open', isOpen);
                localStorage.setItem('tix_shell_settings_more', isOpen ? '1' : '0');
            });

            // Auto-expand if hash points to a "more" tab
            var hash = window.location.hash.replace('#', '');
            if (hash) {
                var moreTab = moreItems.querySelector('[data-settings-tab="' + hash + '"]');
                if (moreTab) {
                    moreItems.classList.add('open');
                    moreBtn.classList.add('open');
                }
            }
        }


        // ═══════════════════════════════════════
        // DOCS TAB SWITCHING
        // ═══════════════════════════════════════
        var docsTabs = sidebar.querySelectorAll('.tix-shell-docs-tab');

        if (docsTabs.length) {
            var hash = window.location.hash.replace('#', '');
            if (hash) {
                activateDocsTab(hash);
            }

            docsTabs.forEach(function(item) {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    var tab = this.dataset.docsTab;
                    if (tab) {
                        activateDocsTab(tab);
                        history.replaceState(null, '', '#' + tab);
                    }
                });
            });
        }

        function activateDocsTab(tabName) {
            var navTab = document.querySelector('.tix-nav-tab[data-tab="' + tabName + '"]');
            if (navTab) {
                navTab.click();
            }
            docsTabs.forEach(function(item) {
                item.classList.toggle('active', item.dataset.docsTab === tabName);
            });
        }


        // ═══════════════════════════════════════
        // SUPPORT TAB SWITCHING
        // ═══════════════════════════════════════
        var supportTabs = sidebar.querySelectorAll('.tix-shell-support-tab');

        if (supportTabs.length) {
            var hash = window.location.hash.replace('#', '');
            if (hash) {
                activateSupportTab(hash);
            }

            supportTabs.forEach(function(item) {
                item.addEventListener('click', function(e) {
                    e.preventDefault();
                    var tab = this.dataset.supportTab;
                    if (tab) {
                        activateSupportTab(tab);
                        history.replaceState(null, '', '#' + tab);
                    }
                });
            });
        }

        function activateSupportTab(tabName) {
            var navTab = document.querySelector('.tix-nav-tab[data-tab="' + tabName + '"]');
            if (navTab) {
                navTab.click();
            }
            supportTabs.forEach(function(item) {
                item.classList.toggle('active', item.dataset.supportTab === tabName);
            });
        }


        // ═══════════════════════════════════════
        // MOBILE SIDEBAR TOGGLE
        // ═══════════════════════════════════════
        var mobileBtn = document.querySelector('.tix-shell-mobile-toggle');
        if (mobileBtn) {
            mobileBtn.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }

        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', function(e) {
            if (window.innerWidth <= 782 &&
                sidebar.classList.contains('open') &&
                !sidebar.contains(e.target) &&
                (!mobileBtn || !mobileBtn.contains(e.target))) {
                sidebar.classList.remove('open');
            }
        });


        // ═══════════════════════════════════════
        // KEYBOARD: Escape to go back to WP
        // ═══════════════════════════════════════
        // (Disabled – could be annoying. Uncomment if wanted.)
        // document.addEventListener('keydown', function(e) {
        //     if (e.key === 'Escape' && !e.target.matches('input, textarea, select')) {
        //         window.location.href = sidebar.querySelector('.tix-shell-back').href;
        //     }
        // });
    });
})();
