/**
 * Blt Community — Address typeahead for the registration form.
 * Debounced fetch against /wp-json/cmm/v1/addresses
 */
document.addEventListener('DOMContentLoaded', function () {
    var input    = document.getElementById('cmm-address-input');
    var codeBox  = document.getElementById('cmm-address-code-display');
    var hiddenId = document.getElementById('cmm-home-id');
    var dropdown = document.getElementById('cmm-address-dropdown');

    if (!input || !dropdown) return;

    var timer;

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = this.value.trim();

        if (q.length < 2) {
            dropdown.style.display = 'none';
            return;
        }

        timer = setTimeout(function () {
            fetch('/wp-json/cmm/v1/addresses?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (results) {
                    dropdown.innerHTML = '';

                    if (!Array.isArray(results) || !results.length) {
                        dropdown.innerHTML = '<div class="cmm-no-results">No matching addresses found</div>';
                    } else {
                        results.forEach(function (item) {
                            var opt = document.createElement('div');
                            opt.className   = 'cmm-dropdown-option';
                            opt.textContent = item.address;

                            opt.addEventListener('click', function () {
                                input.value            = item.address;
                                hiddenId.value         = item.id;
                                codeBox.textContent    = 'Address Code: ' + item.address_code;
                                codeBox.style.display  = 'block';
                                dropdown.style.display = 'none';
                            });

                            dropdown.appendChild(opt);
                        });
                    }

                    dropdown.style.display = 'block';
                })
                .catch(function () {
                    dropdown.style.display = 'none';
                });
        }, 250);
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    // Keyboard navigation
    input.addEventListener('keydown', function (e) {
        if (dropdown.style.display === 'none') return;

        var options = dropdown.querySelectorAll('.cmm-dropdown-option');
        var current = dropdown.querySelector('.cmm-dropdown-option.cmm-active');
        var idx     = Array.prototype.indexOf.call(options, current);

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (current) current.classList.remove('cmm-active');
            var next = options[idx + 1] || options[0];
            if (next) next.classList.add('cmm-active');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (current) current.classList.remove('cmm-active');
            var prev = options[idx - 1] || options[options.length - 1];
            if (prev) prev.classList.add('cmm-active');
        } else if (e.key === 'Enter') {
            if (current) {
                e.preventDefault();
                current.click();
            }
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
        }
    });
});
