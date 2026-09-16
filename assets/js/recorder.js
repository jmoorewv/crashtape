( function () {
	'use strict';

	if ( window.__crashtapeRecorderLoaded ) {
		return;
	}
	window.__crashtapeRecorderLoaded = true;

	if ( typeof CrashTapeRecorder === 'undefined' || ! CrashTapeRecorder.endpoint ) {
		return;
	}

	var endpoint = CrashTapeRecorder.endpoint;
	var queue = [];
	var FLUSH_INTERVAL_MS = 4000;
	var FLUSH_AT_COUNT = 10;

	function isOwnRequest( url ) {
		return typeof url === 'string' && url.indexOf( endpoint ) !== -1;
	}

	function safeUrl( url ) {
		if ( typeof url !== 'string' ) {
			return '';
		}
		return url.split( '?' )[ 0 ];
	}

	function push( evt ) {
		queue.push( evt );
		if ( queue.length >= FLUSH_AT_COUNT ) {
			flush( false );
		}
	}

	function flush( useBeacon ) {
		if ( ! queue.length ) {
			return;
		}

		var payload = JSON.stringify( { events: queue } );
		queue = [];

		if ( useBeacon && navigator.sendBeacon ) {
			try {
				navigator.sendBeacon( endpoint, new Blob( [ payload ], { type: 'application/json' } ) );
				return;
			} catch ( e ) {
				// fall through to fetch
			}
		}

		if ( typeof fetch === 'function' ) {
			fetch( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: payload,
				keepalive: true,
			} ).catch( function () {} );
		}
	}

	window.addEventListener( 'error', function ( e ) {
		push( {
			event_type: 'js_error',
			severity: 'error',
			summary: String( e.message || 'Script error' ).substring( 0, 500 ),
			data: {
				source: e.filename || '',
				line: e.lineno || 0,
				col: e.colno || 0,
			},
		} );
	} );

	window.addEventListener( 'unhandledrejection', function ( e ) {
		var reason = e.reason && e.reason.message ? e.reason.message : String( e.reason );
		push( {
			event_type: 'unhandled_rejection',
			severity: 'error',
			summary: reason.substring( 0, 500 ),
			data: {},
		} );
	} );

	// --- fetch instrumentation ---
	if ( typeof window.fetch === 'function' ) {
		var originalFetch = window.fetch;

		window.fetch = function ( input, init ) {
			var url = typeof input === 'string' ? input : ( input && input.url ) || '';

			if ( isOwnRequest( url ) ) {
				return originalFetch.apply( this, arguments );
			}

			var method = ( init && init.method ) || 'GET';
			var start = ( window.performance && performance.now ) ? performance.now() : 0;

			return originalFetch.apply( this, arguments ).then(
				function ( response ) {
					if ( ! response.ok ) {
						push( {
							event_type: 'fetch_failed',
							severity: response.status >= 500 ? 'error' : 'warning',
							summary: 'Fetch ' + method + ' ' + safeUrl( url ) + ' returned ' + response.status,
							data: {
								status: response.status,
								duration_ms: Math.round( ( ( window.performance && performance.now ) ? performance.now() : 0 ) - start ),
							},
						} );
					}
					return response;
				},
				function ( err ) {
					push( {
						event_type: 'fetch_error',
						severity: 'error',
						summary: 'Fetch ' + method + ' ' + safeUrl( url ) + ' failed: ' + ( ( err && err.message ) || 'network error' ),
						data: {},
					} );
					throw err;
				}
			);
		};
	}

	// --- XHR instrumentation ---
	if ( window.XMLHttpRequest ) {
		var originalOpen = XMLHttpRequest.prototype.open;
		var originalSend = XMLHttpRequest.prototype.send;

		XMLHttpRequest.prototype.open = function ( method, url ) {
			this.__crashtapeMethod = method;
			this.__crashtapeUrl = url;
			this.__crashtapeIgnore = isOwnRequest( url );
			return originalOpen.apply( this, arguments );
		};

		XMLHttpRequest.prototype.send = function () {
			if ( ! this.__crashtapeIgnore ) {
				var xhr = this;
				var start = ( window.performance && performance.now ) ? performance.now() : 0;

				xhr.addEventListener( 'load', function () {
					if ( xhr.status >= 400 ) {
						push( {
							event_type: 'xhr_failed',
							severity: xhr.status >= 500 ? 'error' : 'warning',
							summary: 'XHR ' + xhr.__crashtapeMethod + ' ' + safeUrl( xhr.__crashtapeUrl ) + ' returned ' + xhr.status,
							data: {
								status: xhr.status,
								duration_ms: Math.round( ( ( window.performance && performance.now ) ? performance.now() : 0 ) - start ),
							},
						} );
					}
				} );

				xhr.addEventListener( 'error', function () {
					push( {
						event_type: 'xhr_error',
						severity: 'error',
						summary: 'XHR ' + xhr.__crashtapeMethod + ' ' + safeUrl( xhr.__crashtapeUrl ) + ' failed',
						data: {},
					} );
				} );
			}

			return originalSend.apply( this, arguments );
		};
	}

	setInterval( function () {
		flush( false );
	}, FLUSH_INTERVAL_MS );

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			flush( true );
		}
	} );

	window.addEventListener( 'pagehide', function () {
		flush( true );
	} );
} )();
