/**
 * Orbit form handler.
 *
 * Intercepts forms with [data-orbit-api] and submits them to the REST API.
 * Also handles response buttons on activity detail pages and subscriber
 * management actions (approve/deny/remove).
 *
 * @package Orbit
 */
( function () {
	'use strict';

	var apiBase = orbitForms.restUrl;
	var nonce   = orbitForms.nonce;

	/**
	 * Last phone number successfully submitted in step 1 of the verify-phone
	 * flow. Cached so the "Resend code" button can re-POST without requiring
	 * the user to re-type the number.
	 */
	var lastSentPhone = '';

	/**
	 * Default request timeout in milliseconds. Long enough to cover slow
	 * third-party round trips (Twilio), short enough that a hung request
	 * doesn't lock the UI for the user.
	 */
	var DEFAULT_TIMEOUT_MS = 30000;

	/**
	 * Send a request to the Orbit REST API.
	 *
	 * @param {string} endpoint  - Relative to orbit/v1/.
	 * @param {string} method    - HTTP method.
	 * @param {Object} data      - Request body (sent as JSON).
	 * @param {number} timeoutMs - Optional override for the request timeout.
	 * @return {Promise}
	 */
	function apiRequest( endpoint, method, data, timeoutMs ) {
		var controller = new AbortController();
		var timer = setTimeout( function () { controller.abort(); }, timeoutMs || DEFAULT_TIMEOUT_MS );

		var options = {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			credentials: 'same-origin',
			signal: controller.signal
		};

		if ( data && method !== 'GET' ) {
			options.body = JSON.stringify( data );
		}

		return fetch( apiBase + endpoint, options ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					var msg = ( body && body.message ) ? body.message : 'An error occurred.';
					var err = new Error( msg );
					err.data = ( body && body.data ) ? body.data : null;
					throw err;
				}
				return body;
			} );
		} ).catch( function ( err ) {
			// Translate AbortError into a clearer timeout message.
			if ( err && err.name === 'AbortError' ) {
				throw new Error( orbitForms.strings.timeout );
			}
			throw err;
		} ).finally( function () {
			clearTimeout( timer );
		} );
	}

	/**
	 * Show a status message near an element.
	 *
	 * @param {HTMLElement} el      - Element to show message near.
	 * @param {string}      message - Message text.
	 * @param {string}      type    - 'success' or 'error'.
	 */
	function showMessage( el, message, type ) {
		// Remove any existing message.
		var existing = el.parentNode.querySelector( '.orbit-message' );
		if ( existing ) {
			existing.remove();
		}

		var div = document.createElement( 'div' );
		div.className = 'orbit-message orbit-message-' + type;
		div.textContent = message;
		el.parentNode.insertBefore( div, el.nextSibling );

		// Scroll the message into view so the user sees it.
		div.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );

		if ( type === 'success' ) {
			setTimeout( function () {
				if ( div.parentNode ) {
					div.style.transition = 'opacity 0.3s ease';
					div.style.opacity = '0';
					setTimeout( function () {
						if ( div.parentNode ) {
							div.remove();
						}
					}, 300 );
				}
			}, 8000 );
		}
	}

	/**
	 * Show an error message followed by an inline link.
	 *
	 * Builds the DOM nodes safely (textContent + setAttribute) so that
	 * server-supplied strings cannot inject markup. The href is validated
	 * via the URL constructor; if it parses, the link is rendered, else
	 * the helper falls back to a plain message.
	 *
	 * @param {HTMLElement} el       - Element to show the message near.
	 * @param {string}      message  - Message text.
	 * @param {string}      href     - URL the inline link should target.
	 * @param {string}      linkText - Visible link text.
	 */
	function showMessageWithLink( el, message, href, linkText ) {
		// Validate the href so we don't inject something unparseable.
		try {
			// eslint-disable-next-line no-new
			new URL( href, window.location.origin );
		} catch ( e ) {
			showMessage( el, message, 'error' );
			return;
		}

		// Remove any existing message.
		var existing = el.parentNode.querySelector( '.orbit-message' );
		if ( existing ) {
			existing.remove();
		}

		var div = document.createElement( 'div' );
		div.className = 'orbit-message orbit-message-error';

		var text = document.createTextNode( message + ' ' );
		div.appendChild( text );

		var link = document.createElement( 'a' );
		link.setAttribute( 'href', href );
		link.textContent = linkText;
		div.appendChild( link );

		el.parentNode.insertBefore( div, el.nextSibling );
		div.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	/**
	 * Collect form data as a plain object.
	 *
	 * @param {HTMLFormElement} form
	 * @return {Object}
	 */
	function collectFormData( form ) {
		var data     = {};
		var elements = form.elements;

		for ( var i = 0; i < elements.length; i++ ) {
			var el = elements[ i ];

			if ( ! el.name || el.disabled ) {
				continue;
			}

			if ( el.type === 'checkbox' ) {
				data[ el.name ] = el.checked ? el.value : '0';
			} else if ( el.type !== 'submit' && el.type !== 'button' ) {
				data[ el.name ] = el.value;
			}
		}

		return data;
	}

	/**
	 * Handle form submissions for [data-orbit-api] forms.
	 */
	document.addEventListener( 'submit', function ( e ) {
		var form = e.target;

		if ( ! form.hasAttribute( 'data-orbit-api' ) ) {
			return;
		}

		e.preventDefault();

		// Per-form in-flight guard. Set synchronously so a second submit
		// event in the same task queue (rapid Enter-key autorepeat or fast
		// double-click) sees the flag before its handler proceeds, even
		// before submitBtn.disabled takes effect. Critical for verify-phone
		// where each request triggers a billed Twilio SMS.
		if ( form.dataset.orbitInFlight === '1' ) {
			return;
		}
		form.dataset.orbitInFlight = '1';

		var endpoint  = form.getAttribute( 'data-orbit-api' );
		var method    = form.getAttribute( 'data-method' ) || 'POST';
		var data      = collectFormData( form );
		var submitBtn = form.querySelector( '[type="submit"]' );

		// Add profile_id from data attribute if present.
		var profileId = form.getAttribute( 'data-profile-id' );
		if ( profileId ) {
			data.profile_id = profileId;
		}

		if ( submitBtn ) {
			submitBtn.disabled = true;
		}

		apiRequest( endpoint, method, data )
			.then( function ( result ) {
				// Phone verification two-step flow.
				if ( endpoint === 'verify-phone' ) {
					var step = form.getAttribute( 'data-orbit-step' );

					if ( step === 'phone' ) {
						// Step 1 succeeded — code was sent. Reveal the code form.
						var section  = form.closest( '.orbit-phone-verification' );
						var codeForm = section ? section.querySelector( '[data-orbit-step="code"]' ) : null;
						var target   = section ? section.querySelector( '.orbit-code-target' ) : null;

						if ( data.phone ) {
							lastSentPhone = data.phone;
						}

						if ( target && data.phone ) {
							target.textContent = data.phone;
						}

						form.hidden = true;

						if ( codeForm ) {
							codeForm.hidden = false;
							var codeInput = codeForm.querySelector( 'input[name="code"]' );
							if ( codeInput ) {
								codeInput.focus();
							}
						}
						return;
					}

					if ( step === 'code' ) {
						// Step 2 succeeded — phone is verified. Reload to show verified state.
						window.location.reload();
						return;
					}
				}

				// Determine whether this success path is about to
				// navigate the user away. When it is, skip the
				// success message so it doesn't flash in the DOM
				// for a microsecond before the redirect fires —
				// it was never actually readable.
				var willRedirect = ( endpoint === 'activities' && method === 'POST' )
					|| ( endpoint === 'signup' )
					|| ( endpoint === 'subscribe' )
					|| ( endpoint === 'profiles/me' );

				if ( ! willRedirect ) {
					// Prefer the server-provided message when present —
					// it's already context-specific ("Your subscription
					// request has been sent for approval." vs. a generic
					// "Saved.").
					var successMessage = ( result && result.message ) || orbitForms.strings.success;
					showMessage( form, successMessage, 'success' );
				}

				// Redirect on certain actions.
				if ( endpoint === 'activities' && method === 'POST' ) {
					if ( result && result.id ) {
						window.location.href = orbitForms.homeUrl + '/activity/' + result.id;
					} else {
						window.location.href = orbitForms.manageUrl;
					}
				} else if ( endpoint === 'signup' || endpoint === 'subscribe' ) {
					// Both endpoints carry a `redirect_url` in the
					// success body — signup points to /edit-profile/,
					// subscribe points to /dashboard/ (new account) or
					// the profile permalink (returning logged-in user).
					// Reject cross-origin destinations defensively:
					// the server already sanitizes with esc_url_raw, but
					// a misconfigured filter could in principle let one
					// through and we don't want a same-origin form to
					// navigate off-site.
					var nextUrl = result && result.redirect_url;
					if ( nextUrl ) {
						try {
							var parsed = new URL( nextUrl, window.location.href );
							if ( parsed.origin === window.location.origin ) {
								window.location.href = nextUrl;
								return;
							}
						} catch ( e ) {
							// Fall through to reload on parse error.
						}
					}
					window.location.reload();
				} else if ( endpoint === 'profiles/me' ) {
					window.location.reload();
				}
			} )
			.catch( function ( err ) {
				// When the signup endpoint hands back a `login_url`
				// (e.g. 409 — email already exists), surface it as
				// an inline "Log in" link so the user has a clear
				// next step right in the error message rather than
				// having to hunt for the footer link.
				if ( endpoint === 'signup' && err && err.data && err.data.login_url ) {
					showMessageWithLink( form, err.message, err.data.login_url, orbitForms.strings.logIn );
					return;
				}
				showMessage( form, err.message, 'error' );
			} )
			.finally( function () {
				delete form.dataset.orbitInFlight;
				if ( submitBtn ) {
					submitBtn.disabled = false;
				}
			} );
	} );

	/**
	 * Handle response buttons (going/maybe) on activity detail pages.
	 */
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '.orbit-response-buttons .orbit-btn' );

		if ( ! button ) {
			return;
		}

		var response       = button.getAttribute( 'data-response' );
		var container      = button.closest( '.orbit-response-buttons' );
		var activityId     = container.getAttribute( 'data-activity-id' );
		var subscriptionId = container.getAttribute( 'data-subscription-id' );
		var tokenInput     = container.querySelector( '.orbit-act-token' );

		if ( ! response || ! activityId ) {
			return;
		}

		// Disable all response buttons.
		var buttons = container.querySelectorAll( '.orbit-btn' );
		buttons.forEach( function ( btn ) {
			btn.disabled = true;
		} );

		// Retract = DELETE, otherwise POST.
		var request;

		if ( response === 'retract' ) {
			request = apiRequest( 'respond', 'DELETE', {
				activity_id: activityId,
				subscription_id: subscriptionId
			} );
		} else {
			var data = {
				activity_id: activityId,
				response: response
			};
			if ( tokenInput ) {
				data.action_token = tokenInput.value;
			}
			request = apiRequest( 'respond', 'POST', data );
		}

		request
			.then( function () {
				if ( response === 'retract' ) {
					// Reload to remove retract button and reset state.
					window.location.reload();
				} else {
					// Update active state.
					buttons.forEach( function ( btn ) {
						btn.classList.remove( 'orbit-btn-active' );
					} );
					button.classList.add( 'orbit-btn-active' );

					// Show retract button if not already present.
					if ( ! container.querySelector( '.orbit-btn-retract' ) ) {
						var retractBtn = document.createElement( 'button' );
						retractBtn.className = 'orbit-btn orbit-btn-sm orbit-btn-retract';
						retractBtn.setAttribute( 'data-response', 'retract' );
						retractBtn.textContent = orbitForms.strings.retract;
						container.appendChild( retractBtn );
					}

					showMessage( container, orbitForms.strings.responseSaved, 'success' );
				}
			} )
			.catch( function ( err ) {
				showMessage( container, err.message, 'error' );
			} )
			.finally( function () {
				buttons.forEach( function ( btn ) {
					btn.disabled = false;
				} );
			} );
	} );

	/**
	 * Handle subscriber management actions (approve/deny/remove).
	 */
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-orbit-subscriber-action]' );

		if ( ! button ) {
			return;
		}

		var action = button.getAttribute( 'data-orbit-subscriber-action' );
		var id     = button.getAttribute( 'data-id' );

		if ( ! action || ! id ) {
			return;
		}

		button.disabled = true;

		apiRequest( 'subscribers/' + id, 'PATCH', { action: action } )
			.then( function () {
				// Reload to reflect updated state.
				window.location.reload();
			} )
			.catch( function ( err ) {
				showMessage( button, err.message, 'error' );
				button.disabled = false;
			} );
	} );

	/**
	 * Handle cancel activity button.
	 */
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-orbit-cancel]' );

		if ( ! button ) {
			return;
		}

		var activityId = button.getAttribute( 'data-orbit-cancel' );

		if ( ! activityId ) {
			return;
		}

		if ( ! window.confirm( orbitForms.strings.confirmCancel ) ) {
			return;
		}

		button.disabled = true;

		apiRequest( 'activities/' + activityId, 'DELETE', {} )
			.then( function () {
				window.location.href = orbitForms.manageUrl;
			} )
			.catch( function ( err ) {
				showMessage( button, err.message, 'error' );
				button.disabled = false;
			} );
	} );
	/**
	 * Handle "Change phone number" / "Use a different number" buttons in
	 * the phone verification block — reveal the phone entry form, hide
	 * the verified display and the code form.
	 */
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-orbit-phone-change]' );

		if ( ! button ) {
			return;
		}

		var section = button.closest( '.orbit-phone-verification' );
		if ( ! section ) {
			return;
		}

		var phoneForm = section.querySelector( '[data-orbit-step="phone"]' );
		var codeForm  = section.querySelector( '[data-orbit-step="code"]' );
		var verified  = section.querySelector( '.orbit-phone-verified' );

		// Defensively re-enable submit buttons on both forms so a previously
		// disabled state (e.g. left over from an aborted submission) doesn't
		// strand the revealed form.
		[ phoneForm, codeForm ].forEach( function ( f ) {
			if ( ! f ) {
				return;
			}
			var btn = f.querySelector( '[type="submit"]' );
			if ( btn ) {
				btn.disabled = false;
			}
		} );

		// Clear any stale code input when toggling back to phone entry.
		if ( codeForm ) {
			var codeIn = codeForm.querySelector( 'input[name="code"]' );
			if ( codeIn ) {
				codeIn.value = '';
			}
		}

		if ( verified ) {
			verified.hidden = true;
		}
		if ( codeForm ) {
			codeForm.hidden = true;
		}
		if ( phoneForm ) {
			phoneForm.hidden = false;
			var input = phoneForm.querySelector( 'input[name="phone"]' );
			if ( input ) {
				input.focus();
			}
		}
	} );

	/**
	 * Handle "Resend code" button in the code form — re-POSTs to verify-phone
	 * with the previously-entered phone (cached when step 1 succeeded), so the
	 * user doesn't have to leave the code-form view to retry.
	 *
	 * Reuses the in-flight guard on the phone form, so a rapid double-click
	 * (or simultaneous Verify submit) cannot trigger a duplicate billed SMS.
	 */
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-orbit-phone-resend]' );

		if ( ! button ) {
			return;
		}

		var section = button.closest( '.orbit-phone-verification' );
		if ( ! section ) {
			return;
		}

		var phoneForm = section.querySelector( '[data-orbit-step="phone"]' );
		if ( ! phoneForm || ! lastSentPhone ) {
			return;
		}

		// Reuse the phone form's in-flight guard so resend can't overlap with
		// an in-flight phone submit (or another resend click).
		if ( phoneForm.dataset.orbitInFlight === '1' ) {
			return;
		}
		phoneForm.dataset.orbitInFlight = '1';

		button.disabled = true;

		apiRequest( 'verify-phone', 'POST', { phone: lastSentPhone } )
			.then( function () {
				showMessage( button, orbitForms.strings.success, 'success' );
			} )
			.catch( function ( err ) {
				showMessage( button, err.message, 'error' );
			} )
			.finally( function () {
				delete phoneForm.dataset.orbitInFlight;
				button.disabled = false;
			} );
	} );

	/**
	 * Handle unsubscribe button on profile pages.
	 */
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-orbit-unsubscribe]' );

		if ( ! button ) {
			return;
		}

		var subscriptionId = button.getAttribute( 'data-orbit-unsubscribe' );

		if ( ! subscriptionId ) {
			return;
		}

		if ( ! window.confirm( orbitForms.strings.confirmUnsubscribe ) ) {
			return;
		}

		button.disabled = true;

		apiRequest( 'subscriptions/' + subscriptionId, 'DELETE', {} )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( err ) {
				showMessage( button, err.message, 'error' );
				button.disabled = false;
			} );
	} );

	/**
	 * Tier-description swap on the New Activity form. Each <option> in the
	 * tier select carries a `data-tier-description` with the explanatory
	 * help text for that commitment level. Updating the matching <p> next
	 * to the select keeps the description honest about what the user just
	 * chose without reloading the page.
	 */
	document.addEventListener( 'change', function ( e ) {
		var select = e.target.closest( '[data-orbit-tier-select]' );
		if ( ! select ) {
			return;
		}

		var form = select.closest( 'form' );
		if ( ! form ) {
			return;
		}

		var help = form.querySelector( '[data-orbit-tier-description]' );
		if ( ! help ) {
			return;
		}

		var option = select.options[ select.selectedIndex ];
		var description = option ? option.getAttribute( 'data-tier-description' ) : '';
		help.textContent = description || '';
	} );

	/**
	 * Dashboard onboarding banner — dismiss button. POSTs to the user's
	 * dismiss endpoint to set the orbit_dashboard_banner_dismissed
	 * user_meta. Uses optimistic visual acknowledgement (aria-busy +
	 * faded opacity) but defers DOM removal until the POST resolves so
	 * a failed request can be surfaced and the banner restored. On
	 * success, keyboard focus is moved to a stable fallback target
	 * before the banner is removed so screen readers and keyboard
	 * users don't land on <body>.
	 */
	document.addEventListener( 'click', function ( e ) {
		var dismissBtn = e.target.closest( '[data-orbit-onboarding-dismiss]' );
		if ( ! dismissBtn ) {
			return;
		}

		var banner = dismissBtn.closest( '[data-orbit-onboarding-banner]' );
		if ( ! banner ) {
			return;
		}

		// Optimistic acknowledgement: keep the banner in the DOM but
		// mark it as dismissing so users see immediate feedback. The
		// element stays available for rollback if the POST fails.
		banner.classList.add( 'orbit-onboarding-banner--dismissing' );
		banner.setAttribute( 'aria-busy', 'true' );
		banner.style.opacity = '0.5';
		dismissBtn.disabled = true;

		// Clear any previous inline error from an earlier failed attempt.
		var prevError = banner.querySelector( '.orbit-onboarding-banner__error' );
		if ( prevError && prevError.parentNode ) {
			prevError.parentNode.removeChild( prevError );
		}

		apiRequest( 'me/dismiss-onboarding-banner', 'POST', {} ).then( function () {
			// Move focus to a stable target before removing the banner
			// so keyboard / screen-reader users don't land on <body>.
			// Preference order: theme-provided hook, then main heading,
			// then <main>, then #main, then body.
			var focusTarget = document.querySelector( '[data-orbit-banner-after]' );
			if ( ! focusTarget ) {
				focusTarget = document.querySelector( 'main h1' );
			}
			if ( ! focusTarget ) {
				focusTarget = document.querySelector( 'main' );
			}
			if ( ! focusTarget ) {
				focusTarget = document.getElementById( 'main' );
			}
			if ( ! focusTarget ) {
				focusTarget = document.body;
			}

			if ( focusTarget ) {
				// Shim tabindex if the target isn't natively focusable
				// so .focus() actually lands somewhere meaningful.
				if ( ! focusTarget.hasAttribute( 'tabindex' ) ) {
					focusTarget.setAttribute( 'tabindex', '-1' );
				}
				// rAF lets the browser settle the DOM mutation that
				// follows; focusing before removal keeps focus stable.
				window.requestAnimationFrame( function () {
					focusTarget.focus();
					if ( banner.parentNode ) {
						banner.parentNode.removeChild( banner );
					}
				} );
			} else if ( banner.parentNode ) {
				banner.parentNode.removeChild( banner );
			}
		} ).catch( function ( err ) {
			// Rollback optimistic state so the banner reappears intact.
			banner.classList.remove( 'orbit-onboarding-banner--dismissing' );
			banner.removeAttribute( 'aria-busy' );
			banner.style.opacity = '';
			dismissBtn.disabled = false;

			// Surface an inline error so the user knows the dismiss
			// didn't persist. Falls back to a literal English string
			// because orbitForms.strings doesn't currently expose a
			// dedicated banner-dismiss error.
			// TODO: i18n — add an `orbit_banner_dismiss_failed` entry
			// to wp_localize_script in orbit.php and prefer it here.
			var message = ( err && err.message )
				? err.message
				: "We couldn't save your preference — please try again.";

			var errorEl = document.createElement( 'span' );
			errorEl.className = 'orbit-onboarding-banner__error';
			errorEl.setAttribute( 'role', 'alert' );
			errorEl.textContent = message;
			banner.appendChild( errorEl );

			// Restore focus to the dismiss button so keyboard users
			// can retry without hunting for it.
			dismissBtn.focus();
		} );
	} );

	/**
	 * Phone-input → SMS-consent gate. The SMS opt-in checkbox is
	 * disabled by default and becomes enabled only when the phone field
	 * has non-empty content. Server-side, if `consent_sms=1` arrives
	 * without a phone the handler rejects with a validation error — the
	 * UX gate here is just so users don't see (and check) a checkbox
	 * they can't act on.
	 */
	document.addEventListener( 'input', function ( e ) {
		var phoneInput = e.target.closest( '[data-orbit-phone-input]' );
		if ( ! phoneInput ) {
			return;
		}

		var form = phoneInput.closest( 'form' );
		if ( ! form ) {
			return;
		}

		var smsConsent = form.querySelector( '[data-orbit-sms-consent]' );
		if ( ! smsConsent ) {
			return;
		}

		var hasPhone = phoneInput.value.trim().length > 0;
		smsConsent.disabled = ! hasPhone;
		if ( ! hasPhone ) {
			smsConsent.checked = false;
		}
	} );

	/**
	 * Copy-to-clipboard button. Reads the target input via
	 * `data-orbit-copy-target` (an ID selector, e.g. `#orbit-share-link`),
	 * copies its value to the clipboard, and briefly swaps the button label
	 * to the confirmation text from `data-orbit-copy-confirm` before
	 * restoring it.
	 *
	 * Selector is validated as ID-only as defense-in-depth: even if a
	 * future XSS pathway let an attacker influence `data-orbit-copy-target`,
	 * resolution is restricted to `getElementById` and never falls through
	 * to arbitrary `querySelector` syntax.
	 */
	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '[data-orbit-copy-target]' );
		if ( ! button ) {
			return;
		}

		var selector = button.getAttribute( 'data-orbit-copy-target' );
		var warn     = function ( message ) {
			if ( window.console && console.warn ) {
				console.warn( '[orbit-copy] ' + message );
			}
		};

		if ( ! selector ) {
			warn( 'data-orbit-copy-target is missing or empty' );
			return;
		}

		// ID-only: `#` followed by [A-Za-z0-9_-]+. Anything else (class,
		// attribute, descendant combinator, etc.) is rejected.
		if ( ! /^#[A-Za-z0-9_-]+$/.test( selector ) ) {
			warn( 'data-orbit-copy-target must be an ID selector, got: ' + selector );
			return;
		}

		var target = document.getElementById( selector.slice( 1 ) );
		if ( ! target ) {
			warn( 'data-orbit-copy-target did not resolve: ' + selector );
			return;
		}

		// Only suppress the click once we've confirmed we'll act on it —
		// otherwise a misconfigured button silently swallows the click.
		e.preventDefault();

		var isTextField  = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement;
		var value        = isTextField ? target.value : target.textContent;
		var confirmLabel = button.getAttribute( 'data-orbit-copy-confirm' ) || 'Copied!';

		// Cache the original label on first interaction so the confirmation
		// text ("Copied!") can never be re-captured as the "default" if the
		// user clicks again during the confirmation window.
		if ( ! button._orbitCopyDefaultLabel ) {
			button._orbitCopyDefaultLabel = button.getAttribute( 'data-orbit-copy-label' ) || button.textContent;
		}
		var defaultLabel = button._orbitCopyDefaultLabel;

		var done = function () {
			button.textContent = confirmLabel;

			// Clear any pending reset from a previous click so the
			// confirmation is shown for the full window after this click,
			// not truncated by the earlier timer.
			if ( button._orbitCopyTimer ) {
				clearTimeout( button._orbitCopyTimer );
			}
			button._orbitCopyTimer = window.setTimeout( function () {
				button.textContent = defaultLabel;
				button._orbitCopyTimer = null;
			}, 1500 );
		};

		var fallback = function () {
			// `select()` only exists on input/textarea — calling it on a
			// <span>/<p> throws TypeError. The current call sites are both
			// inputs, so non-text-field targets are out of scope for the
			// legacy `execCommand` path; warn and bail.
			if ( ! isTextField ) {
				warn( 'clipboard API unavailable and target is not a text input; copy aborted' );
				return;
			}

			try {
				target.focus();
				target.select();
				document.execCommand( 'copy' );
				done();
			} catch ( err ) {
				/* Best effort. Leave the value selected so the user can
				 * still hit Cmd/Ctrl+C themselves. */
			}
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( done ).catch( fallback );
		} else {
			fallback();
		}
	} );
} )();
