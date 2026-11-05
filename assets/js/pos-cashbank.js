/* ============================================================
   Keytill POS — pos-cashbank.js
   Pestaña __( "Register / Bank", 'keytill' ) (solo gestión). Muestra la caja física y la
   caja digital (cuenta bancaria única) con su saldo vivo, los movimientos
   y la lista de transferencias con el tilde de verificación.
   Componente Alpine independiente (keytillCashBank).
   ============================================================ */
( function () {
	'use strict';

	var API = window.wpApiSettings || { root: '/wp-json/', nonce: '' };

	// Textos traducibles. wp.i18n lo encola WordPress como dependencia del script
	// (ver wp_set_script_translations en keytill.php); el fallback deja el ingles.
	var I18N = ( window.wp && window.wp.i18n ) || null;
	var __ = I18N ? I18N.__ : function ( s ) { return s; };
	var sprintf = I18N ? I18N.sprintf : function ( fmt ) {
		var args = Array.prototype.slice.call( arguments, 1 ), i = 0;
		return String( fmt ).replace( /%(?:(\d+)\$)?[sd]/g, function ( _m, pos ) {
			return String( pos ? args[ pos - 1 ] : args[ i++ ] );
		} );
	};

	function getJSON( url ) {
		return fetch( url, { headers: { 'X-WP-Nonce': API.nonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } );
	}

	function sendJSON( url, method, body ) {
		return fetch( url, {
			method: method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': API.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, status: r.status, data: d }; } ); } );
	}

	// Formato de precio: lo provee pos.js segun la config de WooCommerce.
	function fmtMoney( n ) { return window.keytillMoney ? window.keytillMoney( n ) : String( Number( n ) || 0 ); }

	function fmtDate( d ) {
		var m = ( '0' + ( d.getMonth() + 1 ) ).slice( -2 );
		var day = ( '0' + d.getDate() ).slice( -2 );
		return d.getFullYear() + '-' + m + '-' + day;
	}

	function qs( obj ) {
		var p = [];
		Object.keys( obj ).forEach( function ( k ) {
			if ( obj[ k ] !== '' && obj[ k ] !== null && obj[ k ] !== undefined ) {
				p.push( encodeURIComponent( k ) + '=' + encodeURIComponent( obj[ k ] ) );
			}
		} );
		return p.join( '&' );
	}

	function keytillCashBank() {
		return {
			loading: true,
			error: '',
			cash: null,      // caja física (o null si cerrada)
			digital: null,   // caja digital
			busy: false,     // abrir/cerrar digital
			seed: { open: false, initial: '' },               // primer saldo inicial de la digital
			move: { open: false, account: 'digital', kind: 'retiro', amount: '', concept: '', busy: false, error: '' },

			// Libro de movimientos (histórico filtrable).
			ledger: { rows: [], totals: { efectivo: { in: 0, out: 0, net: 0 }, digital: { in: 0, out: 0, net: 0 } }, loading: false },
			lf: { from: '', to: '', origen: '', movement: '', type: '', account: '' },
			lpage: 1,        // página actual del libro
			lperPage: 50,    // máximo de filas por página (el export igual exporta TODO)

			init: function () {
				var self = this;
				// Rango por defecto: del 1º del mes a hoy.
				var now = new Date();
				this.lf.from = fmtDate( new Date( now.getFullYear(), now.getMonth(), 1 ) );
				this.lf.to = fmtDate( now );
				this.load();
				this.loadLedger();
				// Recarga al entrar a la pestaña.
				window.addEventListener( 'keytill:view', function ( e ) {
					if ( e.detail === 'cajabanco' ) { self.load(); self.loadLedger(); }
				} );
			},

			load: function () {
				var self = this;
				this.loading = true;
				this.error = '';
				// Cache-buster: evita que un caché del hosting sirva un estado viejo.
				getJSON( API.root + 'keytill/v1/cashbank/summary?_=' + Date.now() ).then( function ( d ) {
					self.loading = false;
					if ( ! d || d.code ) { self.error = ( d && d.message ) || __( 'Could not load.', 'keytill' ); return; }
					self.cash = d.cash || null;
					self.digital = d.digital || null;
				} ).catch( function () { self.loading = false; self.error = __( 'Could not load.', 'keytill' ); } );
			},

			money: function ( n ) { return fmtMoney( Number( n ) || 0 ); },

			/* ---------- caja digital: abrir / cerrar ---------- */
			openDigital: function () {
				if ( this.digital && this.digital.seeded ) {
					// Reapertura: abre directo con el saldo del último cierre.
					this.doOpenDigital( null );
				} else {
					this.seed = { open: true, initial: '' };
				}
			},
			seedConfirm: function () {
				var v = parseFloat( this.seed.initial );
				if ( isNaN( v ) || v < 0 ) { v = 0; }
				this.seed.open = false;
				this.doOpenDigital( v );
			},
			doOpenDigital: function ( initial ) {
				var self = this;
				if ( this.busy ) { return; }
				this.busy = true;
				this.error = '';
				var body = ( initial === null || initial === undefined ) ? {} : { initial: initial };
				sendJSON( API.root + 'keytill/v1/digital/open', 'POST', body ).then( function ( res ) {
					self.busy = false;
					if ( ! res.ok ) {
						self.error = ( res.data && res.data.message ) || __( 'Could not open the digital register.', 'keytill' );
						self.load(); // refresca el estado real (si ya estaba abierta en otro lado, se ve)
						return;
					}
					self.load();
				} ).catch( function () { self.busy = false; self.error = __( 'Could not open the digital register.', 'keytill' ); } );
			},
			closeDigital: function () {
				if ( this.busy ) { return; }
				if ( ! window.confirm( __( 'Close the digital register? The current balance becomes the opening balance of the next period.', 'keytill' ) ) ) { return; }
				var self = this;
				this.busy = true;
				this.error = '';
				sendJSON( API.root + 'keytill/v1/digital/close', 'POST', {} ).then( function ( res ) {
					self.busy = false;
					if ( ! res.ok ) { self.error = ( res.data && res.data.message ) || __( 'Could not close the digital register.', 'keytill' ); return; }
					self.load();
				} ).catch( function () { self.busy = false; self.error = __( 'Could not close the digital register.', 'keytill' ); } );
			},

			/* ---------- movimientos (retiro / ingreso) ---------- */
			openMove: function ( account, kind ) {
				this.move = { open: true, account: account, kind: kind, amount: '', concept: '', busy: false, error: '' };
			},
			submitMove: function () {
				var m = this.move;
				if ( m.busy ) { return; }
				var amount = parseFloat( m.amount );
				if ( isNaN( amount ) || amount <= 0 ) { m.error = __( 'Enter a valid amount.', 'keytill' ); return; }
				var self = this;
				m.busy = true;
				m.error = '';
				sendJSON( API.root + 'keytill/v1/cash/move', 'POST', {
					account: m.account, kind: m.kind, amount: amount, concept: m.concept
				} ).then( function ( res ) {
					m.busy = false;
					if ( ! res.ok ) { m.error = ( res.data && res.data.message ) || __( 'Could not record the movement.', 'keytill' ); return; }
					self.move.open = false;
					self.load();
				} ).catch( function () { m.busy = false; m.error = __( 'Could not record the movement.', 'keytill' ); } );
			},
			moveTitle: function () {
				var digital = this.move.account === 'digital';
				if ( this.move.kind === 'ingreso' ) {
					return digital ? __( 'Deposit to the digital register', 'keytill' ) : __( 'Deposit to the cash register', 'keytill' );
				}
				return digital ? __( 'Withdrawal from the digital register', 'keytill' ) : __( 'Withdrawal from the cash register', 'keytill' );
			},

			/* ---------- conciliación: tilde de verificación ---------- */
			setVerify: function ( tx, status ) {
				var self = this;
				var next = ( tx.verification === status ) ? 'pendiente' : status; // tocar de nuevo = volver a pendiente
				sendJSON( API.root + 'keytill/v1/digital/verify', 'POST', { order_id: tx.order_id, status: next } ).then( function ( res ) {
					if ( res.ok && res.data ) { tx.verification = res.data.verification; }
				} ).catch( function () {} );
			},
			verifyLabel: function ( v ) {
				if ( v === 'verificada' ) { return 'Verificada'; }
				if ( v === 'no_llego' ) { return __( 'Not received', 'keytill' ); }
				return 'Pendiente';
			},

			/* ---------- libro de movimientos ---------- */
			loadLedger: function () {
				var self = this;
				this.ledger.loading = true;
				var query = qs( {
					from: this.lf.from, to: this.lf.to, origen: this.lf.origen,
					movement: this.lf.movement, type: this.lf.type, account: this.lf.account
				} );
				getJSON( API.root + 'keytill/v1/cashbank/ledger?' + query + '&_=' + Date.now() ).then( function ( d ) {
					self.ledger.loading = false;
					if ( ! d || d.code ) { return; }
					self.ledger.rows = d.rows || [];
					self.ledger.totals = d.totals || self.ledger.totals;
					self.lpage = 1; // vuelve a la primera página al recargar/filtrar
				} ).catch( function () { self.ledger.loading = false; } );
			},

			// Paginado (solo para mostrar; el export usa todas las filas).
			ledgerPages: function () {
				return Math.max( 1, Math.ceil( this.ledger.rows.length / this.lperPage ) );
			},
			pagedRows: function () {
				var start = ( this.lpage - 1 ) * this.lperPage;
				return this.ledger.rows.slice( start, start + this.lperPage );
			},
			ledgerGoPage: function ( p ) {
				var pages = this.ledgerPages();
				if ( p < 1 ) { p = 1; }
				if ( p > pages ) { p = pages; }
				this.lpage = p;
			},

			// Etiquetas legibles.
			origenLabel: function ( o ) { return o === 'web' ? 'Web' : 'Local'; },
			movementLabel: function ( m ) { return m === 'egreso' ? 'Egreso' : 'Ingreso'; },
			accountLabel: function ( a ) { return a === 'digital' ? 'Digital' : 'Efectivo'; },
			typeLabel: function ( t ) {
				if ( t === 'venta' ) { return __( 'Sale', 'keytill' ); }
				if ( t === 'ingreso' ) { return 'Ingreso'; }
				if ( t === 'retiro' ) { return 'Retiro'; }
				if ( t === 'devolucion' ) { return __( 'Refund', 'keytill' ); }
				return t;
			},

			// Exporta las filas visibles a CSV (se descarga).
			exportCsv: function () {
				var rows = this.ledger.rows || [];
				var head = [ __( 'Date', 'keytill' ), __( 'Source', 'keytill' ), __( 'Movement', 'keytill' ), __( 'Type', 'keytill' ), __( 'Account', 'keytill' ), __( 'Amount', 'keytill' ), __( 'Detail', 'keytill' ), __( 'Reconciliation', 'keytill' ) ];
				var self = this;
				var esc = function ( v ) { v = ( v === null || v === undefined ) ? '' : String( v ); return '"' + v.replace( /"/g, '""' ) + '"'; };
				var lines = [ head.map( esc ).join( ',' ) ];
				rows.forEach( function ( r ) {
					lines.push( [
						r.date, self.origenLabel( r.origen ), self.movementLabel( r.movement ),
						self.typeLabel( r.type ), self.accountLabel( r.account ),
						( r.movement === 'egreso' ? '-' : '' ) + Number( r.amount ).toFixed( 2 ),
						r.detail, ( r.order_id ? self.verifyLabel( r.verification ) : '' )
					].map( esc ).join( ',' ) );
				} );
				var csv = '﻿' + lines.join( '\r\n' ); // BOM para Excel
				var blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
				var a = document.createElement( 'a' );
				a.href = URL.createObjectURL( blob );
				a.download = 'caja-banco_' + this.lf.from + '_' + this.lf.to + '.csv';
				document.body.appendChild( a ); a.click(); document.body.removeChild( a );
				setTimeout( function () { URL.revokeObjectURL( a.href ); }, 1000 );
			}
		};
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'keytillCashBank', keytillCashBank );
	} );
}() );
