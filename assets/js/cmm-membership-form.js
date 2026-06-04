/**
 * Blt Community — Native multi-step membership form.
 *
 * Reveals one step at a time, drives the address/account/username live checks
 * against the REST endpoints, and builds the review summary before submit.
 * Degrades to a single long page if JS is disabled (PHP renders panels visible
 * by default; this script hides all but the active step).
 */
(function () {
    'use strict';

    var form = document.querySelector('.cmm-mf');
    if (!form) return;

    var cfg     = window.cmmForm || {};
    var REST    = (cfg.restRoot || '/wp-json/cmm/v1/').replace(/\/?$/, '/');
    var dues    = Number(cfg.duesAmount || 0);
    var notices = cfg.notices || {};

    var panels    = form.querySelectorAll('.cmm-mf-panel');
    var stepItems = form.querySelectorAll('.cmm-mf-steps li');
    var currentStep = 1;

    function showStep(n) {
        currentStep = n;
        panels.forEach(function (p) {
            p.hidden = (parseInt(p.getAttribute('data-step'), 10) !== n);
        });
        stepItems.forEach(function (li) {
            var step = parseInt(li.getAttribute('data-step'), 10);
            li.classList.toggle('active', step === n);
            li.classList.toggle('done',   step < n);
        });
        if (n === 4) buildReview();
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    form.addEventListener('click', function (e) {
        if (e.target.classList.contains('cmm-mf-next')) {
            if (validateStep(currentStep)) showStep(currentStep + 1);
        } else if (e.target.classList.contains('cmm-mf-prev')) {
            showStep(currentStep - 1);
        } else if (e.target.matches('[data-suggest]')) {
            e.preventDefault();
            usernameInput.value = e.target.getAttribute('data-suggest');
            checkUsername();
        }
    });

    function validateStep(n) {
        clearInlineError();
        if (n === 1) {
            if (!homeIdInput.value) {
                showInlineError('Please choose your address from the dropdown.');
                return false;
            }
            return true;
        }
        if (n === 2) {
            var email = emailInput.value.trim();
            if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                showInlineError('Enter a valid email address.');
                return false;
            }
            if (!newAccount.hidden) {
                var u = usernameInput.value.trim();
                if (u.length < 3) {
                    showInlineError('Username must be at least 3 characters.');
                    return false;
                }
                if (!/^[A-Za-z0-9._-]+$/.test(u)) {
                    showInlineError('Username may only contain letters, numbers, dots, dashes, and underscores.');
                    return false;
                }
                if (passwordInput.value.length < 8) {
                    showInlineError('Password must be at least 8 characters.');
                    return false;
                }
            }
            return true;
        }
        if (n === 3) {
            var fn = form.querySelector('[name="first_name"]').value.trim();
            var ln = form.querySelector('[name="last_name"]').value.trim();
            if (!fn || !ln) {
                showInlineError('First and last name are required.');
                return false;
            }
            return true;
        }
        return true;
    }

    // -----------------------------------------------------------------------
    // Address typeahead + home-status fetch
    // -----------------------------------------------------------------------
    var addressInput = form.querySelector('.cmm-mf-address-input');
    var dropdown     = form.querySelector('.cmm-mf-address-dropdown');
    var homeIdInput  = form.querySelector('.cmm-mf-home-id-input');
    var statusCard   = form.querySelector('.cmm-mf-status-card');
    var addrTimer;

    addressInput.addEventListener('input', function () {
        clearTimeout(addrTimer);
        homeIdInput.value  = '';
        statusCard.innerHTML = '';
        var q = this.value.trim();
        if (q.length < 2) { dropdown.hidden = true; return; }
        addrTimer = setTimeout(function () {
            fetch(REST + 'addresses?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(renderAddressDropdown)
                .catch(function () { dropdown.hidden = true; });
        }, 250);
    });

    function renderAddressDropdown(results) {
        dropdown.innerHTML = '';
        if (!Array.isArray(results) || !results.length) {
            dropdown.innerHTML = '<div class="cmm-mf-no-results">No matching addresses found.</div>';
        } else {
            results.forEach(function (item) {
                var opt = document.createElement('div');
                opt.className   = 'cmm-mf-option';
                opt.textContent = item.address;
                opt.addEventListener('click', function () {
                    addressInput.value = item.address;
                    homeIdInput.value  = item.id;
                    dropdown.hidden    = true;
                    fetchHomeStatus(item.id);
                });
                dropdown.appendChild(opt);
            });
        }
        dropdown.hidden = false;
    }

    document.addEventListener('click', function (e) {
        if (!addressInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.hidden = true;
        }
    });

    function fetchHomeStatus(id) {
        statusCard.innerHTML = '<div class="cmm-mf-loading">Checking status…</div>';
        fetch(REST + 'home-status?home_id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(renderStatusCard)
            .catch(function () { statusCard.innerHTML = ''; });
    }

    function renderStatusCard(s) {
        if (!s || s.error) { statusCard.innerHTML = ''; return; }
        var tone, headline, detail;
        if (s.status === 'active') {
            tone = 'info';
            headline = 'This address is currently active.';
            detail = s.dues_paid_date
                ? 'Last payment recorded ' + s.dues_paid_date + '. You can still submit a renewal — it will refresh the cycle.'
                : 'You can still submit a renewal.';
        } else if (s.status === 'expired') {
            tone = 'warn';
            headline = 'This membership has expired.';
            detail   = 'Renew now to reactivate — annual dues are $' + dues.toFixed(2) + '.';
        } else if (s.status === 'inactive') {
            tone = 'ok';
            headline = 'This address is available.';
            detail   = 'Annual dues are $' + dues.toFixed(2) + '.';
        } else {
            tone = 'info';
            headline = s.status_label || s.status;
            detail   = 'Submitting will activate this membership immediately.';
        }
        statusCard.innerHTML =
            '<div class="cmm-mf-card cmm-mf-card-' + tone + '">' +
            '<strong>' + escapeHtml(s.address) + '</strong>' +
            '<p class="cmm-mf-card-headline">' + escapeHtml(headline) + '</p>' +
            '<p class="cmm-mf-card-detail">' + escapeHtml(detail) + '</p>' +
            '</div>';
    }

    // -----------------------------------------------------------------------
    // Email / account check
    // -----------------------------------------------------------------------
    var emailInput   = form.querySelector('.cmm-mf-email-input');
    var newAccount   = form.querySelector('.cmm-mf-new-account');
    var accountFeed  = form.querySelector('.cmm-mf-account-feedback');
    var usernameInput= form.querySelector('.cmm-mf-username-input');
    var passwordInput= form.querySelector('.cmm-mf-password-input');
    var emailTimer;

    emailInput.addEventListener('input', function () {
        clearTimeout(emailTimer);
        var v = this.value.trim();
        if (!v || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)) {
            accountFeed.innerHTML = '';
            newAccount.hidden = true;
            return;
        }
        emailTimer = setTimeout(function () {
            fetch(REST + 'account-check?email=' + encodeURIComponent(v))
                .then(function (r) { return r.json(); })
                .then(applyAccountCheck)
                .catch(function () { accountFeed.innerHTML = ''; });
        }, 350);
    });

    function applyAccountCheck(d) {
        if (d && d.exists) {
            var existingTpl = notices.existingAccount || '';
            var message     = existingTpl.replace(
                '{display_name}',
                escapeHtml(d.display_name || '')
            );
            accountFeed.innerHTML =
                '<div class="cmm-mf-feedback cmm-mf-feedback-info">' + message + '</div>';
            newAccount.hidden = true;
            clearNewAccountInputs();
        } else {
            var newTpl = notices.newAccount || '';
            accountFeed.innerHTML =
                '<div class="cmm-mf-feedback cmm-mf-feedback-new">' + newTpl + '</div>';
            newAccount.hidden = false;
            maybeSuggestUsername();
        }
    }

    function clearNewAccountInputs() {
        // When we're going to attach to an existing account, drop any username
        // or password the visitor may have typed before we knew the email
        // matched — otherwise the form submission would still send credentials
        // the server doesn't need.
        if (usernameInput) usernameInput.value = '';
        if (passwordInput) passwordInput.value = '';
        var ufeed = form.querySelector('.cmm-mf-username-feedback');
        if (ufeed) ufeed.innerHTML = '';
        var meter = form.querySelector('.cmm-mf-password-meter');
        if (meter) meter.innerHTML = '';
    }

    function maybeSuggestUsername() {
        if (usernameInput.value) return;
        var fn = form.querySelector('[name="first_name"]').value.trim().toLowerCase();
        var ln = form.querySelector('[name="last_name"]').value.trim().toLowerCase();
        var email = emailInput.value.trim().toLowerCase();
        var suggestion = '';
        if (fn && ln) {
            suggestion = fn.replace(/[^a-z0-9]/g, '') + '.' + ln.replace(/[^a-z0-9]/g, '');
        } else if (email) {
            suggestion = email.split('@')[0].replace(/[^a-z0-9._-]/g, '');
        }
        if (suggestion) {
            usernameInput.value = suggestion;
            checkUsername();
        }
    }

    // -----------------------------------------------------------------------
    // Username uniqueness check
    // -----------------------------------------------------------------------
    var usernameTimer;
    if (usernameInput) {
        usernameInput.addEventListener('input', function () {
            clearTimeout(usernameTimer);
            usernameTimer = setTimeout(checkUsername, 350);
        });
    }

    function checkUsername() {
        var feed = form.querySelector('.cmm-mf-username-feedback');
        var u    = usernameInput.value.trim();
        if (u.length < 3) { feed.innerHTML = ''; return; }
        fetch(REST + 'username-check?username=' + encodeURIComponent(u))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.available) {
                    feed.innerHTML = '<span class="cmm-mf-ok">&check; Available</span>';
                } else if (d.suggestion) {
                    feed.innerHTML =
                        '<span class="cmm-mf-warn">Taken — try ' +
                        '<a href="#" data-suggest="' + escapeAttr(d.suggestion) + '">' +
                        escapeHtml(d.suggestion) + '</a>?</span>';
                } else {
                    feed.innerHTML = '<span class="cmm-mf-warn">Taken — please choose another.</span>';
                }
            })
            .catch(function () { feed.innerHTML = ''; });
    }

    // -----------------------------------------------------------------------
    // Password strength meter
    // -----------------------------------------------------------------------
    if (passwordInput) {
        passwordInput.addEventListener('input', function () {
            var meter = form.querySelector('.cmm-mf-password-meter');
            var v = this.value;
            if (!v) { meter.innerHTML = ''; return; }
            var score = 0;
            if (v.length >= 8)  score++;
            if (v.length >= 12) score++;
            if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
            if (/[0-9]/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;
            var labels  = ['', 'Very weak', 'Weak', 'OK', 'Good', 'Strong'];
            var classes = ['', 'vweak',     'weak', 'ok', 'good', 'strong'];
            meter.innerHTML =
                '<div class="cmm-mf-strength cmm-mf-strength-' + classes[score] + '">' +
                'Strength: ' + labels[score] +
                '</div>';
        });
    }

    // -----------------------------------------------------------------------
    // Review step
    // -----------------------------------------------------------------------
    function buildReview() {
        var review = form.querySelector('.cmm-mf-review');
        var val    = function (n) {
            var el = form.querySelector('[name="' + n + '"]');
            return el ? el.value.trim() : '';
        };
        var spouseFull = (val('spouse_first_name') + ' ' + val('spouse_last_name')).trim();
        var addrParts  = [val('primary_street'), val('primary_city'), val('primary_state'), val('primary_zip')]
            .filter(function (p) { return p; })
            .join(', ');

        var rows = [
            ['Address',          addressInput.value],
            ['Name',             (val('first_name') + ' ' + val('last_name')).trim()],
            ['Email',            val('email')],
            ['Mobile',           val('mobile') || '—'],
            ['Spouse',           spouseFull || '—'],
            ['Children',         val('children') || '—'],
            ['Directory listed', form.querySelector('[name="directory_listed"]').checked ? 'Yes' : 'No'],
            ['Off-island home',  addrParts || '—'],
        ];

        var html = '<dl class="cmm-mf-review-list">';
        rows.forEach(function (r) {
            html += '<dt>' + escapeHtml(r[0]) + '</dt><dd>' + escapeHtml(r[1]) + '</dd>';
        });
        html += '</dl>';
        review.innerHTML = html;
    }

    // -----------------------------------------------------------------------
    // Inline error helpers
    // -----------------------------------------------------------------------
    function showInlineError(msg) {
        var box = form.querySelector('.cmm-mf-inline-error');
        if (!box) {
            box = document.createElement('div');
            box.className = 'cmm-mf-inline-error';
            form.insertBefore(box, form.firstChild);
        }
        box.textContent = msg;
        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    function clearInlineError() {
        var box = form.querySelector('.cmm-mf-inline-error');
        if (box) box.textContent = '';
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------
    showStep(1);
})();
