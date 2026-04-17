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
	 * Send a request to the Orbit REST API.
	 *
	 * @param {string} endpoint - Relative to orbit/v1/.
	 * @param {string} method   - HTTP method.
	 * @param {Object} data     - Request body (sent as JSON).
	 * @return {Promise}
	 */
	function apiRequest( endpoint, method, data ) {
		var options = {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			credentials: 'same-origin'
		};

		if ( data && method !== 'GET' ) {
			options.body = JSON.stringify( data );
		}

		return fetch( apiBase + endpoint, options ).then( function ( response ) {
			return response.json().then( function ( body ) {
				if ( ! response.ok ) {
					var msg = ( body && body.message ) ? body.message : 'An error occurred.';
					throw new Error( msg );
				}
				return body;
			} );
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
				showMessage( form, orbitForms.strings.success, 'success' );

				// Redirect on certain actions.
				if ( endpoint === 'activities' && method === 'POST' ) {
					if ( result && result.id ) {
						window.location.href = orbitForms.homeUrl + '/activity/' + result.id;
					} else {
						window.location.href = orbitForms.manageUrl;
					}
				} else if ( endpoint === 'subscribe' || endpoint === 'profiles/me' ) {
					window.location.reload();
				}
			} )
			.catch( function ( err ) {
				showMessage( form, err.message, 'error' );
			} )
			.finally( function () {
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

		var response  = button.getAttribute( 'data-response' );
		var container = button.closest( '.orbit-response-buttons' );
		var activityId = container.getAttribute( 'data-activity-id' );
		var tokenInput = container.querySelector( '.orbit-act-token' );

		if ( ! response || ! activityId ) {
			return;
		}

		var data = {
			activity_id: activityId,
			response: response
		};

		if ( tokenInput ) {
			data.action_token = tokenInput.value;
		}

		// Disable all response buttons.
		var buttons = container.querySelectorAll( '.orbit-btn' );
		buttons.forEach( function ( btn ) {
			btn.disabled = true;
		} );

		apiRequest( 'respond', 'POST', data )
			.then( function () {
				// Update active state.
				buttons.forEach( function ( btn ) {
					btn.classList.remove( 'orbit-btn-active' );
				} );
				button.classList.add( 'orbit-btn-active' );
				showMessage( container, orbitForms.strings.responseSaved, 'success' );
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
} )();
