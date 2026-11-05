/* ============================================================
   Keytill POS — pos-orders.js
   Pestaña "Pedidos" de la consola. Componente Alpine independiente
   (keytillOrders) montado en su vista; lee $store.pos para saber si
   está activa. Lista paginada + filtros + detalle (reimprimir / devolver).
   La devolución reutiliza el flujo F7 del componente de venta vía el
   evento 'keytill:return'.
   ============================================================ */
( function () {
	'use strict';

	var API = window.wpApiSettings || { root: '/wp-json/', nonce: '' };

	function ymd( d ) {
		var m = ( '0' + ( d.getMonth() + 1 ) ).slice( -2 );
		var day = ( '0' + d.getDate() ).slice( -2 );
		return d.getFullYear() + '-' + m + '-' + day;
	}

	function qs( obj ) {
		var parts = [];
		Object.keys( obj ).forEach( function ( k ) {
			var v = obj[ k ];
			if ( v !== '' && v !== null && v !== undefined ) {
				parts.push( encodeURIComponent( k ) + '=' + encodeURIComponent( v ) );
			}
		} );
		return parts.join( '&' );
	}

	function getJSON( url ) {
		return fetch( url, { headers: { 'X-WP-Nonce': API.nonce }, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } );
	}

	function keytillOrders() {
		return {
			items: [],
			total: 0,
			pages: 1,
			page: 1,
			perPage: 25,
			filters: { search: '', status: '', payment_method: '', origin: '', cajero_id: '', date_from: '', date_to: '' },
			preset: 'todo',
			meta: { cashiers: [], payment_methods: [], statuses: [] },
			loading: false,
			loaded: false,
			selected: null,
			detailLoading: false,
			error: '',
			_searchTimer: null,

			init: function () {
				var self = this;
				// Carga perezosa: recién al activar la pestaña por primera vez.
				this._onView = function ( e ) { if ( e.detail === 'pedidos' ) { self.ensureLoaded(); } };
				window.addEventListener( 'keytill:view', this._onView );
				if ( this.$store.pos.view === 'pedidos' ) { this.ensureLoaded(); }
			},

			ensureLoaded: function () {
				if ( this.loaded ) { return; }
				this.loaded = true;
				this.loadFilters();
				this.load( 1 );
			},

			loadFilters: function () {
				var self = this;
				getJSON( API.root + 'keytill/v1/orders/filters' )
					.then( function ( d ) { if ( d ) { self.meta = d; } } )
					.catch( function () {} );
			},

			cleanFilters: function () {
				var f = this.filters, o = {};
				[ 'search', 'status', 'payment_method', 'origin', 'cajero_id', 'date_from', 'date_to' ].forEach( function ( k ) {
					if ( f[ k ] !== '' && f[ k ] != null ) { o[ k ] = f[ k ]; }
				} );
				return o;
			},

			load: function ( page ) {
				var self = this;
				this.loading = true;
				this.error = '';
				var params = Object.assign( { page: page || 1, per_page: this.perPage }, this.cleanFilters() );
				getJSON( API.root + 'keytill/v1/orders/list?' + qs( params ) )
					.then( function ( d ) {
						self.loading = false;
						self.items = ( d && d.items ) || [];
						self.total = ( d && d.total ) || 0;
						self.pages = ( d && d.pages ) || 1;
						self.page = ( d && d.page ) || 1;
					} )
					.catch( function () { self.loading = false; self.error = 'No se pudo cargar el listado.'; } );
			},

			apply: function () { this.load( 1 ); },

			onSearch: function () {
				var self = this;
				clearTimeout( this._searchTimer );
				this._searchTimer = setTimeout( function () { self.load( 1 ); }, 250 );
			},

			setPreset: function ( p ) {
				this.preset = p;
				var today = new Date();
				if ( p === 'todo' ) {
					this.filters.date_from = '';
					this.filters.date_to = '';
				} else {
					var days = p === 'hoy' ? 0 : ( p === '7d' ? 6 : 29 );
					var from = new Date( today.getTime() );
					from.setDate( from.getDate() - days );
					this.filters.date_from = ymd( from );
					this.filters.date_to = ymd( today );
				}
				this.load( 1 );
			},

			// Rango de fechas manual (inputs Desde/Hasta): desactiva el preset.
			setCustomRange: function () {
				this.preset = 'custom';
				this.load( 1 );
			},

			// Descarga el CSV de pedidos respetando los filtros actuales. Exige un
			// rango de fechas (Desde y Hasta) para no exportar todo el histórico.
			exportCsv: function () {
				var cfg = window.keytillConfig || {};
				if ( ! cfg.ordersExport ) { this.error = 'No se pudo generar la exportación.'; return; }
				if ( ! this.filters.date_from || ! this.filters.date_to ) {
					this.error = 'Elegí un rango de fechas (Desde y Hasta) para exportar.';
					return;
				}
				this.error = '';
				var sep = cfg.ordersExport.indexOf( '?' ) === -1 ? '?' : '&';
				var url = cfg.ordersExport + sep + qs( this.cleanFilters() );
				window.open( url, '_blank' );
			},

			goPage: function ( p ) {
				if ( p < 1 || p > this.pages ) { return; }
				this.load( p );
			},

			open: function ( id ) {
				var self = this;
				this.detailLoading = true;
				this.selected = null;
				getJSON( API.root + 'keytill/v1/orders/' + id )
					.then( function ( d ) { self.detailLoading = false; self.selected = d; } )
					.catch( function () { self.detailLoading = false; self.error = 'No se pudo abrir el pedido.'; } );
			},

			closeDetail: function () { this.selected = null; },

			reprint: function () {
				if ( this.selected && this.selected.print_url ) {
					window.open( this.selected.print_url, '_blank', 'noopener' );
				}
			},

			reprintGift: function () {
				if ( this.selected && this.selected.gift_url ) {
					window.open( this.selected.gift_url, '_blank', 'noopener' );
				}
			},

			refund: function () {
				if ( ! this.selected ) { return; }
				// Reutiliza el flujo de devolución (F7) del componente de venta.
				window.dispatchEvent( new CustomEvent( 'keytill:return', { detail: { number: this.selected.number } } ) );
				this.closeDetail();
				this.$store.pos.go( 'venta' );
			}
		};
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'keytillOrders', keytillOrders );
	} );
}() );
