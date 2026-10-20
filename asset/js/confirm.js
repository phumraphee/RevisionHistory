/**
 * What this save would change, shown before it is made.
 *
 * The one rule this file is written around: Omeka's save must still work. So the
 * dialog only ever stands between the form and the server when everything has gone
 * right — any error, any slow answer, any surprise, and the save goes through exactly
 * as it would without this module. It never blocks a save, it only delays one.
 *
 * Omeka's own script fills the form's hidden `values_json` when the form is submitted.
 * This listener is bound on window load, after that one, so by the time it runs the
 * form already carries everything the server needs; it stops the submit, asks the
 * server what would change, and submits the same form again once the person says yes.
 */
(function () {
    'use strict';

    var cfg = window.RevisionHistoryConfirm;
    if (!cfg || !cfg.url) {
        return;
    }

    function ready(fn) {
        if (document.readyState === 'complete') {
            fn();
        } else {
            window.addEventListener('load', fn);
        }
    }

    /** The forms being watched on this page, with what is known about each. */
    var watched = [];

    ready(function () {
        var forms = document.querySelectorAll(cfg.selector || '#content form');
        Array.prototype.forEach.call(forms, function (form) {
            if (form.getAttribute('data-rh-confirm') === 'on' || form.closest('.sidebar')) {
                return;
            }
            form.setAttribute('data-rh-confirm', 'on');
            var state = { form: form, through: false, asking: false, touched: false };
            watched.push(state);
            attach(state);
            if (cfg.mode !== 'batch') {
                warnOnLeaving(state);
            }
        });
        if (cfg.mode !== 'batch') {
            askBeforeLeaving();
        }
    });

    /**
     * Omeka warns before leaving a page with unsaved changes, but it decides that by
     * comparing the form's own fields — and the boxes a person types a value into have
     * no name of their own: they are gathered into the hidden values_json only when the
     * form is submitted. So editing values and walking away asks nothing, and the work
     * is gone. Telling Omeka, in its own way, that the form has been touched is enough
     * to make its own warning appear. Only what a person really did counts (isTrusted);
     * a change the page makes to itself while loading is not somebody's work.
     *
     * Making a value private (the eye) and removing a value are work too, but they are
     * links, not fields: Omeka's own script changes the form behind them and says
     * nothing — no input, no change. So a press on one of them counts as well.
     */
    function warnOnLeaving(state) {
        var form = state.form;
        var mark = function (ev) {
            if (!ev.isTrusted) {
                return;
            }
            if (ev.type === 'click') {
                var link = ev.target && ev.target.closest ? ev.target.closest(VALUE_ACTIONS) : null;
                if (!link || !form.contains(link)) {
                    return;
                }
            }
            state.touched = true;
            if (!window.Omeka || !window.Omeka.markDirty) {
                return;
            }
            window.Omeka.markDirty(form);
            form.removeEventListener('input', mark, true);
            form.removeEventListener('change', mark, true);
            form.removeEventListener('click', mark, true);
        };
        form.addEventListener('input', mark, true);
        form.addEventListener('change', mark, true);
        form.addEventListener('click', mark, true);
    }

    /** The links on a value that change it without a field saying so. */
    var VALUE_ACTIONS = 'a.value-visibility, a.remove-value, a.restore-value';

    function attach(state) {
        var form = state.form;

        form.addEventListener('submit', function (ev) {
            if (state.through || state.asking || ev.defaultPrevented) {
                return;
            }
            var submitter = ev.submitter || null;
            // A button that says it does something else (delete, add another media) is
            // not a save to show.
            if (submitter && submitter.getAttribute('data-rh-skip') !== null) {
                return;
            }
            ev.preventDefault();
            state.asking = true;

            var done = false;
            var go = function () {
                if (done) {
                    return;
                }
                done = true;
                state.through = true;
                close();
                submit(form, submitter);
            };
            var stop = function () {
                if (done) {
                    return;
                }
                done = true;
                state.asking = false;
                close();
                // Omeka marks a form as submitted the moment the submit starts, and stops
                // warning about unsaved changes from then on. This save did not happen, so
                // the mark is taken off again and its warning works as it always did.
                if (window.jQuery) {
                    window.jQuery(form).data('omekaFormSubmitted', false);
                }
            };
            // Whatever happens, the save is never held for longer than this.
            var timer = window.setTimeout(go, cfg.wait || 8000);

            if (cfg.mode === 'batch') {
                window.clearTimeout(timer);
                window.setTimeout(function () {
                    show(batchBody(form), go, stop);
                }, 0);
                return;
            }

            waiting();
            // Read the form only once every other listener on this submit has run:
            // Omeka's own fills the hidden values_json there, and its check of the
            // required fields stops the save there too.
            window.setTimeout(function () {
                if (window.jQuery && window.jQuery(form).data('has-error')) {
                    window.clearTimeout(timer);
                    stop();
                    return;
                }
                var body;
                try {
                    body = payload(form, submitter);
                } catch (e) {
                    window.clearTimeout(timer);
                    go();
                    return;
                }
                ask(body, timer, go, stop);
            }, 0);
        });
    }

    /** Ask the server what would change. go() and stop() each only ever act once. */
    function ask(body, timer, go, stop) {
        window.fetch(cfg.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                if (!r.ok) {
                    throw new Error(r.status);
                }
                // Nothing would change: there is nothing to ask about.
                if (r.headers.get('X-Revision-History-Changes') === '0') {
                    throw new Error('nothing');
                }
                return r.text();
            }).then(function (html) {
                window.clearTimeout(timer);
                show(html, go, stop);
            })['catch'](function () {
                window.clearTimeout(timer);
                go();
            });
    }

    /**
     * What a batch edit says it will do, read off the form itself: every field that has
     * been filled in, under the name the page gives it. Read from the page and not from
     * a list kept here, so the words are always Omeka's own — and a field another module
     * puts on this form is shown too, in that module's words.
     */
    function batchBody(form) {
        var rows = [];
        Array.prototype.forEach.call(form.querySelectorAll('.field'), function (fld) {
            if (fld.parentNode && fld.parentNode.closest && fld.parentNode.closest('.field') === fld) {
                return;
            }
            var said = [];
            Array.prototype.forEach.call(fld.querySelectorAll('input, select, textarea'), function (el) {
                if (el.disabled || el.type === 'hidden' || el.type === 'submit' || el.type === 'button' || !el.name) {
                    return;
                }
                if (el.type === 'checkbox' || el.type === 'radio') {
                    if (el.checked && el.value !== '') {
                        said.push(labelOf(el) || el.value);
                    }
                    return;
                }
                if (el.tagName === 'SELECT') {
                    Array.prototype.forEach.call(el.selectedOptions || [], function (o) {
                        if (o.value !== '') {
                            said.push(o.text);
                        }
                    });
                    return;
                }
                if (String(el.value).trim() !== '') {
                    said.push(el.value);
                }
            });
            if (!said.length) {
                return;
            }
            var name = fld.querySelector('.field-meta label');
            rows.push('<li class="rh-c rhc-two"><span class="rh-f">'
                + esc(name ? name.textContent.trim() : '') + '</span><span class="rh-v">'
                + esc(said.join(' · ')) + '</span></li>');
        });
        var n = form.querySelectorAll('input[name="resource_ids[]"]').length;
        var head = '<div class="rhc-tally"><span class="rhc-ed">' + esc(count(n)) + '</span></div>';
        if (!rows.length) {
            return head + '<p class="rhc-empty">' + esc(cfg.nothing || 'Nothing has been filled in.') + '</p>';
        }
        return head + '<ul class="rh-changes rhc-list">' + rows.join('') + '</ul>';
    }

    function count(n) {
        var one = cfg.one || '%s', many = cfg.many || '%s';
        return (n === 1 ? one : many).replace('%s', n);
    }

    function labelOf(el) {
        var lab = el.closest('label');
        if (lab) {
            return lab.textContent.trim();
        }
        var by = el.id && document.querySelector('label[for="' + el.id + '"]');
        return by ? by.textContent.trim() : '';
    }

    /**
     * Leaving the page with work nobody has saved.
     *
     * The browser has a warning of its own for this, but it is the browser's: its words
     * cannot be changed, it says nothing about what is at stake, and it offers no way to
     * save. So a link that would leave this page is stopped first and the same list of
     * changes is shown, with a Save button on it. Only then, if the person still wants
     * to go, the page is left — and the browser's own warning is taken out of the way,
     * so nobody is asked the same question twice.
     *
     * Closing the tab and the back button cannot be caught this way; there the browser's
     * own warning is the only one there is, and it still works.
     */
    function askBeforeLeaving() {
        document.addEventListener('click', function (ev) {
            if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
                return;
            }
            if (standAside) {
                return;
            }
            var link = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
            if (!link || !leaves(link)) {
                return;
            }
            var state = dirty();
            if (!state) {
                return;
            }
            // Omeka's Cancel button steps back in the history from its own click
            // handler, whatever anybody else did with the event, so this one is stopped
            // outright rather than only having its default taken away.
            ev.preventDefault();
            ev.stopPropagation();
            if (ev.stopImmediatePropagation) {
                ev.stopImmediatePropagation();
            }
            state.asking = true;
            var body;
            try {
                gather(state.form);
                body = payload(state.form, null, true);
            } catch (e) {
                leave(link);
                return;
            }
            waiting();
            var timer = window.setTimeout(function () {
                leave(link);
            }, cfg.wait || 8000);
            window.fetch(cfg.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                if (!r.ok) {
                    throw new Error(r.status);
                }
                // Touched, but nothing came of it: there is nothing to lose by leaving.
                if (r.headers.get('X-Revision-History-Changes') === '0') {
                    throw new Error('nothing');
                }
                return r.text();
            }).then(function (html) {
                window.clearTimeout(timer);
                leaving(html, state, link);
            })['catch'](function () {
                window.clearTimeout(timer);
                leave(link);
            });
        }, true);
    }

    /**
     * Fill the form's hidden values_json without submitting it.
     *
     * Omeka gathers every value into that one field in its own submit handler, so
     * outside a real submit it holds whatever it held when the page was drawn — which
     * would read as "every value removed". Running that handler alone (jQuery's
     * triggerHandler calls the handlers and nothing else) is what makes the form say
     * what it holds now. Nothing is submitted and nothing is written.
     */
    function gather(form) {
        if (window.jQuery) {
            window.jQuery(form).triggerHandler('submit');
            return;
        }
        var guard = function (e) {
            e.preventDefault();
        };
        form.addEventListener('submit', guard, true);
        try {
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        } catch (e) {
        }
        form.removeEventListener('submit', guard, true);
    }

    /**
     * What this link would do, if it would take the person off the page: the way to do
     * it afterwards, or null when it does not.
     *
     * Only links that certainly leave are answered for, and they are named one by one:
     * Omeka's own Cancel button, the navigation menu, the header, and a real address in
     * the page's own actions. Everything else is left alone — the admin pages are full
     * of links with real addresses that Omeka answers itself without leaving, and the
     * plainest of them is the next page of the panel for picking an item to link to.
     * Stopping one of those and offering to leave is worse than not asking at all: it
     * is how somebody loses the work this was built to protect. So the question here is
     * never "does this look like it leaves" but "is this one of the few we know does".
     */
    function leaves(link) {
        var href = link.getAttribute('href') || '';
        // Omeka's own Cancel button: an anchor to nothing that steps back in the history
        // from its own click handler (admin.js), so it is known by what it is.
        if (link.classList.contains('cancel') && link.classList.contains('button')) {
            return true;
        }
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) {
            return false;
        }
        if ((link.target && link.target !== '_self') || link.hasAttribute('download')) {
            return false;
        }
        // Anything Omeka opens in a panel on the same page, and anything inside one.
        if (link.classList.contains('sidebar-content')
            || link.hasAttribute('data-sidebar-content-url')
            || link.hasAttribute('data-sidebar-selector')
            || link.closest('.sidebar, .rhc, [data-sidebar-selector]')
        ) {
            return false;
        }
        // The navigation menu, the header above it, and the page's own actions: the ways
        // out of a page that really are ways out.
        if (!link.closest('#menu, header, #page-actions')) {
            return false;
        }
        // A link to this very page with only a different fragment stays here.
        return link.href.split('#')[0] !== window.location.href.split('#')[0];
    }

    /** The watched form somebody has typed in and not saved, if there is one. */
    function dirty() {
        for (var i = 0; i < watched.length; i++) {
            var st = watched[i];
            if (st.touched && !st.through && !st.asking) {
                return st;
            }
        }
        return null;
    }

    /**
     * Go: press the link again, with this listener standing aside.
     *
     * Not "send the browser to that address" — press the same link. Whatever Omeka does
     * with it, it does. If this ever asks about a link that turns out not to leave at
     * all, the worst that happens is a question nobody needed: the link still does its
     * own work, and nobody's work is thrown away by us going somewhere it wasn't going.
     */
    function leave(link) {
        watched.forEach(function (st) {
            st.through = true;
            if (window.jQuery) {
                window.jQuery(st.form).data('omekaFormSubmitted', true);
            }
        });
        close();
        standAside = true;
        try {
            link.click();
        } finally {
            window.setTimeout(function () {
                standAside = false;
            }, 0);
        }
    }

    /** True while a link this file stopped is being pressed again. */
    var standAside = false;

    function leaving(html, state, link) {
        var box = frame();
        box.innerHTML = '<div class="rhc-head"><h2>' + esc(cfg.leaveTitle || 'Before you leave') + '</h2>'
            + '<div class="rhc-what">' + esc(cfg.leaveWhat || '') + '</div></div>'
            + '<div class="rhc-body"></div>'
            + '<div class="rhc-foot">'
            + '<button type="button" class="rhc-save">' + esc(cfg.save || 'Save') + '</button>'
            + '<button type="button" class="rhc-leave">' + esc(cfg.leave || 'Leave without saving') + '</button>'
            + '<button type="button" class="rhc-cancel">' + esc(cfg.stay || 'Keep editing') + '</button>'
            + '</div>';
        box.querySelector('.rhc-body').innerHTML = html;
        var stay = function () {
            state.asking = false;
            close();
        };
        // Saving from here has already shown what would change: it goes straight through.
        box.querySelector('.rhc-save').addEventListener('click', function () {
            state.asking = false;
            state.through = true;
            close();
            submit(state.form, null);
        });
        box.querySelector('.rhc-leave').addEventListener('click', function () {
            leave(link);
        });
        box.querySelector('.rhc-cancel').addEventListener('click', stay);
        shade.addEventListener('click', function (ev) {
            if (ev.target === shade) {
                stay();
            }
        });
        document.addEventListener('keydown', function onKey(ev) {
            if (!shade) {
                document.removeEventListener('keydown', onKey);
                return;
            }
            if (ev.key === 'Escape') {
                stay();
            }
        });
        box.querySelector('.rhc-save').focus();
    }

    /** The form as it stands, without the files: the preview reads words, not uploads. */
    function payload(form, submitter, leaving) {
        var all = new FormData(form);
        var out = new FormData();
        all.forEach(function (value, key) {
            if (typeof value === 'string') {
                out.append(key, value);
            }
        });
        if (submitter && submitter.name) {
            out.append(submitter.name, submitter.value || '');
        }
        out.append('revision_history_kind', cfg.kind || 'item');
        out.append('revision_history_id', cfg.id || 0);
        // Where the resource stood when this page was opened, so that the answer can say
        // whether somebody else has saved it since.
        if (cfg.seen || cfg.opened) {
            out.append('revision_history_seen', cfg.seen || 0);
            out.append('revision_history_opened', cfg.opened || '');
        }
        if (leaving) {
            out.append('revision_history_leaving', '1');
        }
        return out;
    }

    function submit(form, submitter) {
        if (form.requestSubmit) {
            form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
        } else {
            if (submitter && submitter.name) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = submitter.name;
                hidden.value = submitter.value || '';
                form.appendChild(hidden);
            }
            form.submit();
        }
    }

    var shade = null;

    function close() {
        if (shade && shade.parentNode) {
            shade.parentNode.removeChild(shade);
        }
        shade = null;
    }

    function frame() {
        close();
        shade = document.createElement('div');
        shade.className = 'rhc-shade';
        shade.innerHTML = '<div class="rhc" role="dialog" aria-modal="true" aria-label="'
            + esc(cfg.title || 'Before you save') + '"></div>';
        document.body.appendChild(shade);
        return shade.firstChild;
    }

    function waiting() {
        var box = frame();
        box.innerHTML = '<div class="rhc-head"><h2>' + esc(cfg.title || 'Before you save') + '</h2></div>'
            + '<div class="rhc-empty">' + esc(cfg.waiting || 'Working…') + '</div>';
    }

    function show(html, go, stop) {
        var box = frame();
        box.innerHTML = '<div class="rhc-head"><h2>' + esc(cfg.title || 'Before you save') + '</h2>'
            + '<div class="rhc-what">' + esc(cfg.what || '') + '</div></div>'
            + '<div class="rhc-body"></div>'
            + '<div class="rhc-foot">'
            + '<button type="button" class="rhc-save">' + esc(cfg.save || 'Save') + '</button>'
            + '<button type="button" class="rhc-cancel">' + esc(cfg.cancel || 'Cancel') + '</button>'
            + '</div>';
        box.querySelector('.rhc-body').innerHTML = html;
        var save = box.querySelector('.rhc-save');
        save.addEventListener('click', go);
        box.querySelector('.rhc-cancel').addEventListener('click', stop);
        shade.addEventListener('click', function (ev) {
            if (ev.target === shade) {
                stop();
            }
        });
        document.addEventListener('keydown', function onKey(ev) {
            if (!shade) {
                document.removeEventListener('keydown', onKey);
                return;
            }
            if (ev.key === 'Escape') {
                stop();
            }
        });
        save.focus();
    }

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
}());
