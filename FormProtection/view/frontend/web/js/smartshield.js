/**
 * SmartShield v2.0 — Invisible Bot Protection
 *
 * Protection layers:
 *   1. Strips form action on page load — forms can't submit without validation
 *   2. Intercepts native form submit events
 *   3. Intercepts fetch() calls to protected form action URLs
 *   4. Intercepts XMLHttpRequest to protected form action URLs
 *   5. Restores action ONLY after validation passes
 *
 * Usage:
 *   <script src="https://ai.devexa.ro/js/smartshield.js?key=dxk_xxx" defer></script>
 */
(function () {
    'use strict';

    // ---- Auto-config from script tag ----
    var scriptTag = document.currentScript || (function () {
        var scripts = document.querySelectorAll('script[src*="smartshield"]');
        return scripts[scripts.length - 1];
    })();

    var scriptUrl = scriptTag ? scriptTag.src : '';
    var keyMatch = scriptUrl.match(/[?&]key=([^&]+)/);
    var autoApiKey = keyMatch ? keyMatch[1] : null;

    var platformBase = '';
    if (scriptUrl) {
        try { platformBase = new URL(scriptUrl).origin; } catch (e) {}
    }

    var config = window.SmartShieldConfig || {};
    if (autoApiKey && !config.apiKey) config.apiKey = autoApiKey;
    if (!config.validateUrl && platformBase && config.apiKey) {
        config.validateUrl = platformBase + '/v1/form-protection/validate';
    }
    if (!config.formSelectors || !config.formSelectors.length) {
        config.formSelectors = [
            'form.form-login', '#login-form',
            'form.form-create-account', '#form-validate',
            '#contact-form', '#newsletter-validate-detail', '#review-form'
        ];
    }

    if (!config.validateUrl || !config.apiKey) {
        if (window.console) console.warn('[SmartShield] Missing config. Add ?key=YOUR_API_KEY to the script URL.');
        return;
    }

    // ---- State ----
    var protectedActions = {};  // formId -> original action URL
    var validatedForms = {};    // formId -> true (passed validation)
    var pendingValidation = {}; // formId -> true (validation in progress)
    var formCounter = 0;

    // ---- Behavior collectors ----
    var behavior = {
        mouse_movements: 0,
        keystrokes: [],
        focus_events: 0,
        paste_detected: false,
        page_loaded_at: Date.now()
    };

    document.addEventListener('mousemove', function () { behavior.mouse_movements++; });
    document.addEventListener('keydown', function () { behavior.keystrokes.push(Date.now()); });
    document.addEventListener('focus', function () { behavior.focus_events++; }, true);
    document.addEventListener('paste', function () { behavior.paste_detected = true; }, true);

    function guessCountry() {
        try {
            var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            var map = {
                'Asia/Shanghai': 'CN', 'Asia/Chongqing': 'CN', 'Asia/Harbin': 'CN',
                'Europe/Moscow': 'RU', 'Asia/Yekaterinburg': 'RU',
                'Asia/Pyongyang': 'KP', 'Asia/Tehran': 'IR'
            };
            return map[tz] || null;
        } catch (e) { return null; }
    }

    function getBehaviorPayload() {
        var avgTypingSpeed = 999;
        if (behavior.keystrokes.length > 2) {
            var intervals = [];
            for (var i = 1; i < behavior.keystrokes.length; i++) {
                intervals.push(behavior.keystrokes[i] - behavior.keystrokes[i - 1]);
            }
            avgTypingSpeed = Math.round(intervals.reduce(function (a, b) { return a + b; }, 0) / intervals.length);
        }

        // Check honeypot fields — any filled = bot
        var honeypotTriggered = false;
        document.querySelectorAll('input[name^="ss_hp_"]').forEach(function (hp) {
            if (hp.value && hp.value.trim() !== '') honeypotTriggered = true;
        });

        return {
            avg_typing_speed_ms: avgTypingSpeed,
            mouse_movements: behavior.mouse_movements,
            focus_events: behavior.focus_events,
            paste_detected: behavior.paste_detected,
            time_on_page_seconds: Math.round((Date.now() - behavior.page_loaded_at) / 1000),
            honeypot_triggered: honeypotTriggered
        };
    }

    // ---- Validation call ----
    function validateWithPlatform(callback) {
        var payload = getBehaviorPayload();
        var country = guessCountry();

        // Quick client-side country block
        if (country && config.blockedCountries && config.blockedCountries.indexOf(country) !== -1) {
            callback({ action: 'block', block_message: null });
            return;
        }

        var xhr = new _XMLHttpRequest();
        xhr.open('POST', config.validateUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('Authorization', 'Bearer ' + config.apiKey);
        xhr.timeout = 5000;
        xhr.onload = function () {
            try {
                var result = JSON.parse(xhr.responseText);
                if (result.error === 'service_inactive') {
                    if (window.console) console.warn('[SmartShield] Service inactive:', result.message);
                    callback({ action: 'allow' });
                } else {
                    callback(result);
                }
            } catch (e) {
                callback({ action: 'allow' }); // fail open
            }
        };
        xhr.onerror = function () { callback({ action: 'allow' }); };
        xhr.ontimeout = function () { callback({ action: 'allow' }); };
        xhr.send(JSON.stringify({ behavior: payload, country: country }));
    }

    // ---- Challenge / Block UI ----
    function showChallenge(type, onPass) {
        var overlay = document.createElement('div');
        overlay.className = 'smartshield-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;';
        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;padding:2rem;border-radius:8px;max-width:400px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);font-family:-apple-system,BlinkMacSystemFont,sans-serif;';

        if (type === 'delay') {
            box.innerHTML = '<p style="font-size:1.1rem;margin-bottom:1rem;">Verifying your request...</p><p id="ss-countdown" style="font-size:2rem;font-weight:bold;">3</p>';
            overlay.appendChild(box); document.body.appendChild(overlay);
            var count = 3;
            var timer = setInterval(function () {
                count--;
                var el = document.getElementById('ss-countdown');
                if (el) el.textContent = String(count);
                if (count <= 0) { clearInterval(timer); overlay.remove(); onPass(); }
            }, 1000);
        } else if (type === 'checkbox') {
            box.innerHTML = '<p style="margin-bottom:1rem;">Please confirm you are human:</p><label style="cursor:pointer;font-size:1rem;"><input type="checkbox" id="ss-confirm" style="margin-right:0.5rem;"/> I am not a robot</label>';
            overlay.appendChild(box); document.body.appendChild(overlay);
            document.getElementById('ss-confirm').addEventListener('change', function () {
                if (this.checked) { overlay.remove(); onPass(); }
            });
        } else if (type === 'math') {
            var a = Math.floor(Math.random() * 10) + 1, b = Math.floor(Math.random() * 10) + 1;
            box.innerHTML = '<p style="margin-bottom:1rem;">What is ' + a + ' + ' + b + '?</p><input type="number" id="ss-math" style="padding:0.5rem;font-size:1.2rem;width:80px;text-align:center;border:1px solid #ccc;border-radius:4px;"/><br/><button type="button" id="ss-math-btn" style="margin-top:1rem;padding:0.5rem 2rem;background:#333;color:#fff;border:none;border-radius:4px;cursor:pointer;">Submit</button><p id="ss-math-error" style="color:red;margin-top:0.5rem;display:none;">Wrong answer, try again.</p>';
            overlay.appendChild(box); document.body.appendChild(overlay);
            document.getElementById('ss-math-btn').addEventListener('click', function () {
                if (parseInt(document.getElementById('ss-math').value, 10) === a + b) { overlay.remove(); onPass(); }
                else { document.getElementById('ss-math-error').style.display = 'block'; }
            });
        }
    }

    function showBlockMessage(message) {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:99999;';
        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;padding:2rem;border-radius:8px;max-width:400px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.3);font-family:-apple-system,BlinkMacSystemFont,sans-serif;';
        box.innerHTML = '<p style="color:#c00;font-size:1.1rem;">' + (message || 'This submission has been blocked for security reasons.') + '</p><button type="button" style="margin-top:1rem;padding:0.5rem 2rem;background:#333;color:#fff;border:none;border-radius:4px;cursor:pointer;" onclick="this.closest(\'div\').parentElement.remove()">Close</button>';
        overlay.appendChild(box); document.body.appendChild(overlay);
    }

    // ---- Handle validation result for a form ----
    function handleResult(form, formId, result, onAllow) {
        pendingValidation[formId] = false;

        if (result.action === 'allow') {
            validatedForms[formId] = true;
            // Restore action temporarily
            if (protectedActions[formId]) {
                form.action = protectedActions[formId];
            }
            onAllow();
            // Re-strip action after a tick (in case form doesn't navigate away)
            setTimeout(function () {
                if (form && protectedActions[formId]) {
                    validatedForms[formId] = false;
                    form.action = 'javascript:void(0)';
                }
            }, 2000);
        } else if (result.action === 'challenge') {
            showChallenge(result.challenge_type || 'delay', function () {
                validatedForms[formId] = true;
                if (protectedActions[formId]) {
                    form.action = protectedActions[formId];
                }
                onAllow();
                setTimeout(function () {
                    if (form && protectedActions[formId]) {
                        validatedForms[formId] = false;
                        form.action = 'javascript:void(0)';
                    }
                }, 2000);
            });
        } else {
            showBlockMessage(result.block_message);
        }
    }

    // ---- Protect a form ----
    // Check if a form matches any exclude selector
    function isExcluded(form) {
        var excludes = config.excludeSelectors || [];
        for (var i = 0; i < excludes.length; i++) {
            try {
                if (form.matches(excludes[i])) return true;
                // Also check if form's action URL matches
                var action = form.getAttribute('action') || '';
                if (excludes[i].indexOf('*') !== -1) {
                    // Simple wildcard: form[action*="cart"] → check if action contains "cart"
                    var match = excludes[i].match(/\[action\*="([^"]+)"\]/);
                    if (match && action.indexOf(match[1]) !== -1) return true;
                }
            } catch (e) {}
        }
        return false;
    }

    function protectForm(form) {
        if (form.dataset.smartshieldProtected) return;

        // Skip excluded forms
        if (isExcluded(form)) {
            form.dataset.smartshieldProtected = 'excluded';
            return;
        }

        formCounter++;
        var formId = 'ss_' + formCounter;
        form.dataset.smartshieldProtected = formId;

        // Store real action and strip it
        var realAction = form.getAttribute('action') || form.action || '';
        if (realAction && realAction !== 'javascript:void(0)') {
            protectedActions[formId] = realAction;
            form.action = 'javascript:void(0)';
        }

        // Honeypot — hidden fields that bots fill but humans don't see
        var honeypotNames = ['website_url', 'phone_number_confirm', 'full_address'];
        honeypotNames.forEach(function (name) {
            var hp = document.createElement('input');
            hp.type = 'text';
            hp.name = 'ss_hp_' + name;
            hp.tabIndex = -1;
            hp.autocomplete = 'off';
            hp.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:0;height:0;opacity:0;pointer-events:none;';
            hp.setAttribute('aria-hidden', 'true');
            form.appendChild(hp);
        });

        // Badge
        if (config.showBadge !== false && config.badgeText) {
            var badge = document.createElement('div');
            badge.style.cssText = 'font-size:0.7rem;color:#999;margin-top:0.5rem;text-align:center;';
            badge.textContent = config.badgeText;
            form.appendChild(badge);
        }

        // Intercept native submit
        form.addEventListener('submit', function (e) {
            // Already validated — let through
            if (validatedForms[formId]) {
                return;
            }

            // Prevent submission
            e.preventDefault();
            e.stopImmediatePropagation();

            // Avoid double validation
            if (pendingValidation[formId]) return;
            pendingValidation[formId] = true;

            validateWithPlatform(function (result) {
                handleResult(form, formId, result, function () {
                    // Re-submit the form natively
                    var submitBtn = form.querySelector('[type="submit"], button:not([type="button"])');
                    if (submitBtn) {
                        submitBtn.click();
                    } else if (form.requestSubmit) {
                        form.requestSubmit();
                    } else {
                        HTMLFormElement.prototype.submit.call(form);
                    }
                });
            });
        }, true); // Use capture to run before other handlers
    }

    // ---- Intercept fetch() for AJAX form submissions ----
    var _fetch = window.fetch;
    window.fetch = function (input, init) {
        var url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
        var method = (init && init.method) ? init.method.toUpperCase() : 'GET';

        // Only intercept POST/PUT requests to protected action URLs
        if (method === 'POST' || method === 'PUT') {
            var matchedFormId = findProtectedFormByAction(url);
            if (matchedFormId && !validatedForms[matchedFormId]) {
                // Block this AJAX call until validated
                return new Promise(function (resolve, reject) {
                    if (pendingValidation[matchedFormId]) {
                        reject(new Error('[SmartShield] Validation in progress'));
                        return;
                    }
                    pendingValidation[matchedFormId] = true;

                    var form = document.querySelector('[data-smartshield-protected="' + matchedFormId + '"]');
                    validateWithPlatform(function (result) {
                        if (result.action === 'allow') {
                            validatedForms[matchedFormId] = true;
                            pendingValidation[matchedFormId] = false;
                            // Retry the original fetch
                            _fetch(input, init).then(resolve).catch(reject);
                            // Reset after completion
                            setTimeout(function () { validatedForms[matchedFormId] = false; }, 2000);
                        } else if (result.action === 'challenge') {
                            pendingValidation[matchedFormId] = false;
                            showChallenge(result.challenge_type || 'delay', function () {
                                validatedForms[matchedFormId] = true;
                                _fetch(input, init).then(resolve).catch(reject);
                                setTimeout(function () { validatedForms[matchedFormId] = false; }, 2000);
                            });
                        } else {
                            pendingValidation[matchedFormId] = false;
                            showBlockMessage(result.block_message);
                            reject(new Error('[SmartShield] Blocked'));
                        }
                    });
                });
            }
        }

        return _fetch(input, init);
    };

    // ---- Intercept XMLHttpRequest for AJAX form submissions ----
    var _XMLHttpRequest = window.XMLHttpRequest;
    var XHRProto = _XMLHttpRequest.prototype;
    var _xhrOpen = XHRProto.open;
    var _xhrSend = XHRProto.send;

    XHRProto.open = function (method, url) {
        this._ssMethod = method;
        this._ssUrl = url;
        return _xhrOpen.apply(this, arguments);
    };

    XHRProto.send = function (body) {
        var xhr = this;
        var method = (xhr._ssMethod || '').toUpperCase();
        var url = xhr._ssUrl || '';

        if (method === 'POST' || method === 'PUT') {
            var matchedFormId = findProtectedFormByAction(url);
            if (matchedFormId && !validatedForms[matchedFormId]) {
                // Block and validate first
                if (pendingValidation[matchedFormId]) return;
                pendingValidation[matchedFormId] = true;

                validateWithPlatform(function (result) {
                    if (result.action === 'allow') {
                        validatedForms[matchedFormId] = true;
                        pendingValidation[matchedFormId] = false;
                        _xhrSend.call(xhr, body);
                        setTimeout(function () { validatedForms[matchedFormId] = false; }, 2000);
                    } else if (result.action === 'challenge') {
                        pendingValidation[matchedFormId] = false;
                        showChallenge(result.challenge_type || 'delay', function () {
                            validatedForms[matchedFormId] = true;
                            _xhrSend.call(xhr, body);
                            setTimeout(function () { validatedForms[matchedFormId] = false; }, 2000);
                        });
                    } else {
                        pendingValidation[matchedFormId] = false;
                        showBlockMessage(result.block_message);
                        // Fire error event on the XHR
                        if (xhr.onerror) xhr.onerror(new Error('[SmartShield] Blocked'));
                    }
                });
                return; // Don't send yet
            }
        }

        return _xhrSend.call(xhr, body);
    };

    // ---- Helper: find protected form by its original action URL ----
    function findProtectedFormByAction(url) {
        if (!url) return null;
        // Normalize URL for comparison
        var normalizedUrl;
        try { normalizedUrl = new URL(url, window.location.origin).pathname; } catch (e) { normalizedUrl = url; }

        for (var formId in protectedActions) {
            var action = protectedActions[formId];
            try {
                var normalizedAction = new URL(action, window.location.origin).pathname;
                if (normalizedUrl === normalizedAction || url.indexOf(action) !== -1 || action.indexOf(url) !== -1) {
                    return formId;
                }
            } catch (e) {
                if (url.indexOf(action) !== -1) return formId;
            }
        }
        return null;
    }

    // ---- Init ----
    function init() {
        var selector = config.formSelectors.join(', ');
        try {
            document.querySelectorAll(selector).forEach(protectForm);
        } catch (e) {
            if (window.console) console.warn('[SmartShield] Invalid selector:', e.message);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Watch for dynamically added forms
    var observer = new MutationObserver(function (mutations) {
        var selector = config.formSelectors.join(', ');
        for (var i = 0; i < mutations.length; i++) {
            var added = mutations[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
                if (added[j].nodeType === 1) {
                    try {
                        if (added[j].matches && added[j].matches(selector)) protectForm(added[j]);
                        var nested = added[j].querySelectorAll ? added[j].querySelectorAll(selector) : [];
                        nested.forEach(protectForm);
                    } catch (e) {}
                }
            }
        }
    });
    observer.observe(document.body || document.documentElement, { childList: true, subtree: true });

    // Expose for debugging
    window.SmartShield = {
        version: '2.0.0',
        getBehavior: getBehaviorPayload,
        getProtectedForms: function () { return protectedActions; },
        getValidated: function () { return validatedForms; },
        config: config
    };

})();
