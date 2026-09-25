/**
 * Shared interaction handler for the Blog Manager tab and plugin modal.
 *
 * OJS Website Settings and AJAX modals do not reliably execute scripts
 * embedded in injected HTML. This file is registered in the backend page
 * head, so it is available before either copy of the manager is rendered.
 */
(function () {
    'use strict';

    if (window.jedlBlogManagerBound) {
        return;
    }
    window.jedlBlogManagerBound = true;

    function getManager(element) {
        return element && element.closest ? element.closest('.jedl-blog-manager') : null;
    }

    function syncEditor(form) {
        var manager = getManager(form);
        var editor = manager ? manager.querySelector('.jedl-blog-editor-area') : null;
        var content = manager ? manager.querySelector('textarea[name="content"]') : null;

        if (editor && content) {
            content.value = editor.innerHTML;
        }
    }

    function tokenFor(form) {
        var field = form.querySelector('[name="csrfToken"]');
        if (field && field.value) {
            return field.value;
        }

        var candidates = document.querySelectorAll('input[name="csrfToken"]');
        for (var i = 0; i < candidates.length; i++) {
            if (candidates[i].value) {
                if (field) {
                    field.value = candidates[i].value;
                }
                return candidates[i].value;
            }
        }

        if (typeof window.csrfToken === 'string' && window.csrfToken) {
            if (field) {
                field.value = window.csrfToken;
            }
            return window.csrfToken;
        }

        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) {
            if (field) {
                field.value = meta.content;
            }
            return meta.content;
        }

        return '';
    }

    function replaceContent(form, response) {
        if (!response || typeof response.content !== 'string') {
            return false;
        }

        // Prefer the manager containing the submitted form. This keeps the
        // tab and modal independent if both happen to be open.
        var manager = getManager(form);
        if (manager) {
            manager.outerHTML = response.content;
            return true;
        }

        var modal = form.closest ? form.closest('.pkp_modal_content, .pkp_modal') : null;
        if (modal) {
            modal.innerHTML = response.content;
            return true;
        }

        return false;
    }

    function requestError(xhr) {
        var status = xhr.status ? ' (HTTP ' + xhr.status + ')' : '';
        return 'The Blog action could not be completed' + status + '. Please reload the page and try again.';
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.matches('#jedlBlogEntryForm, .jedl-blog-action-form')) {
            return;
        }

        // Forms rendered by v3.3.2.1 use their own onsubmit handler. This
        // fallback remains for any manager markup held in an old OJS cache.
        if (form.getAttribute('data-jedl-direct-submit') === 'true') {
            return;
        }

        event.preventDefault();
        syncEditor(form);

        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        var token = tokenFor(form);
        if (!token) {
            window.alert('OJS CSRF token was not found. Please reload the Blog Manager and try again.');
            return;
        }

        var confirmation = form.getAttribute('data-confirm');
        if (confirmation && !window.confirm(confirmation)) {
            return;
        }

        var button = form.querySelector('button[type="submit"]');
        var originalLabel = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = form.classList.contains('jedl-blog-action-form') ? 'Working...' : 'Saving...';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== XMLHttpRequest.DONE) {
                return;
            }

            try {
                if (xhr.status < 200 || xhr.status >= 300) {
                    throw new Error(requestError(xhr));
                }

                var response = JSON.parse(xhr.responseText);
                if (response.status === false) {
                    throw new Error(typeof response.content === 'string' ? response.content : requestError(xhr));
                }

                if (!replaceContent(form, response)) {
                    throw new Error('The server returned an invalid Blog Manager response.');
                }
            } catch (error) {
                if (button) {
                    button.disabled = false;
                    button.textContent = originalLabel;
                }
                window.alert('Blog action failed: ' + error.message);
            }
        };
        xhr.send(new FormData(form));
    // Capture the event before OJS's modal form handler stops propagation.
    // Website Settings does not have that handler, which is why this matters
    // specifically for the plugin modal.
    }, true);

    document.addEventListener('click', function (event) {
        var clearButton = event.target.closest ? event.target.closest('#jedlBlogClear') : null;
        if (!clearButton) {
            return;
        }

        var manager = getManager(clearButton);
        var form = manager ? manager.querySelector('#jedlBlogEntryForm') : null;
        var editor = manager ? manager.querySelector('.jedl-blog-editor-area') : null;
        var content = manager ? manager.querySelector('textarea[name="content"]') : null;

        if (form) {
            form.reset();
        }
        if (editor) {
            editor.innerHTML = '';
        }
        if (content) {
            content.value = '';
        }
    }, false);
}());
