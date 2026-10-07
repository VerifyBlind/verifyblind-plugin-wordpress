/* VerifyBlind for WordPress — starts the VerifyBlind widget for a box, then lets the server check the signed
 * result. A box with data-reload="1" reloads the page on success so the server renders the open state; a box
 * inside a form (data-reload="0") keeps what the visitor typed and the server checks again on submit. */
(function () {
	'use strict';
	var cfg = window.VerifyBlindWP || {};

	function setMessage(box, text, isError) {
		var el = box.querySelector('.verifyblind-box__msg');
		if (!el) return;
		el.textContent = text || '';
		el.className = 'verifyblind-box__msg' + (isError ? ' is-error' : '');
	}

	function withParams(url, params) {
		var sep = url.indexOf('?') === -1 ? '?' : '&';
		return url + sep + Object.keys(params).map(function (k) {
			return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
		}).join('&');
	}

	function start(box) {
		var btn = box.querySelector('.verifyblind-start');
		var container = box.querySelector('.verifyblind-container');
		if (!window.VerifyBlind || !container) {
			setMessage(box, cfg.i18n && cfg.i18n.failed, true);
			return;
		}
		btn.hidden = true;
		setMessage(box, '', false);
		var restore = function () { btn.hidden = false; };
		// The REST nonce is sent only for logged-in visitors (it is empty for guests): WordPress refuses a
		// request carrying a stale nonce, while a guest without one is simply a guest.
		var genParams = { rule: box.getAttribute('data-rule') };
		var verifyHeaders = { 'Content-Type': 'application/json' };
		if (cfg.restNonce) {
			genParams._wpnonce = cfg.restNonce;
			verifyHeaders['X-WP-Nonce'] = cfg.restNonce;
		}
		window.VerifyBlind.init({
			// The widget's own fetch cannot add headers: the REST nonce travels as _wpnonce so the
			// logged-in user is recognised. The server ignores any validations the browser sends.
			generateUrl: withParams(cfg.generateUrl, genParams),
			captcha: cfg.captcha === '1',
			containerId: container.id,
			locale: cfg.locale,
			onSuccess: function (data) {
				fetch(cfg.verifyUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: verifyHeaders,
					body: JSON.stringify({ token: data && data.token })
				})
					.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
					.then(function (res) {
						if (res.ok && res.body && res.body.passed) {
							if (box.getAttribute('data-reload') === '0') {
								box.classList.add('is-verified');
								setMessage(box, cfg.i18n.verified, false);
							} else {
								setMessage(box, cfg.i18n.success, false);
								window.location.reload();
							}
						} else {
							setMessage(box, (res.body && res.body.message) || cfg.i18n.failed, true);
							restore();
						}
					})
					.catch(function () { setMessage(box, cfg.i18n.failed, true); restore(); });
			},
			onError: function (err) { setMessage(box, (err && err.message) || cfg.i18n.failed, true); restore(); },
			onCancelled: restore
		});
	}

	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest ? e.target.closest('.verifyblind-start') : null;
		if (!btn) return;
		e.preventDefault();
		start(btn.closest('.verifyblind-box'));
	});
})();
