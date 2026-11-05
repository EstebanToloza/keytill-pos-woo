/* ============================================================
   HM Sport POS — pos-customers.js
   Pestaña "Clientes" de la consola. Componente Alpine independiente
   (hmposCustomers) montado en su vista; lee $store.pos para saber si
   está activa. Lista paginada + búsqueda + detalle (edición + estadísticas
   + historial de compras) + alta. Clientes = usuarios WP rol customer,
   unificados con la web. El POS NO manda emails. Permiso: operate.
   ============================================================ */
( function () {
	'use strict';

	var API = window.wpApiSettings || { root: '/wp-json/', nonce: '' };

	// Debe coincidir con HMPOS_Customers::fiscal_options() (default primero).
	var FISCAL = [ 'Consumidor Final', 'Responsable Inscripto', 'Monotributista', 'Exento', 'No Responsable' ];

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

	function sendJSON( url, method, body ) {
		return fetch( url, {
			method: method,
			headers: { 'X-WP-Nonce': API.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( d ) { return { ok: r.ok, status: r.status, data: d }; } );
		} );
	}

	// Extrae el cliente existente de una respuesta 409 de conflicto.
	function conflictCustomer( res ) {
		var d = res && res.data;
		if ( d && d.data && d.data.customer ) { return d.data.customer; }
		return null;
	}

	function hmposCustomers() {
		return {
			items: [],
			total: 0,
			pages: 1,
			page: 1,
			perPage: 20,
			search: '',
			loading: false,
			loaded: false,
			error: '',
			_searchTimer: null,
			fiscalOptions: FISCAL,

			// detalle / edición
			selected: null,
			detailLoading: false,
			saveBusy: false,
			saveError: '',
			saveConflict: null,

			// alta
			createOpen: false,
			createBusy: false,
			createError: '',
			createConflict: null,
			newCust: { name: '', email: '', doc: '', iva: 'Consumidor Final' },

			init: function () {
				var self = this;
				this._onView = function ( e ) { if ( e.detail === 'clientes' ) { self.ensureLoaded(); } };
				window.addEventListener( 'hmpos:view', this._onView );
				if ( this.$store.pos.view === 'clientes' ) { this.ensureLoaded(); }
			},

			ensureLoaded: function () {
				if ( this.loaded ) { return; }
				this.loaded = true;
				this.load( 1 );
			},

			load: function ( page ) {
				var self = this;
				this.loading = true;
				this.error = '';
				var params = { page: page || 1, per_page: this.perPage };
				if ( this.search !== '' ) { params.search = this.search; }
				getJSON( API.root + 'hmpos/v1/customers/list?' + qs( params ) )
					.then( function ( d ) {
						self.loading = false;
						self.items = ( d && d.items ) || [];
						self.total = ( d && d.total ) || 0;
						self.pages = ( d && d.pages ) || 1;
						self.page = ( d && d.page ) || 1;
					} )
					.catch( function () { self.loading = false; self.error = 'No se pudo cargar el listado.'; } );
			},

			onSearch: function () {
				var self = this;
				clearTimeout( this._searchTimer );
				this._searchTimer = setTimeout( function () { self.load( 1 ); }, 250 );
			},

			goPage: function ( p ) {
				if ( p < 1 || p > this.pages ) { return; }
				this.load( p );
			},

			/* ---------- detalle / edición ---------- */
			open: function ( id ) {
				var self = this;
				this.detailLoading = true;
				this.selected = null;
				this.saveError = '';
				this.saveConflict = null;
				getJSON( API.root + 'hmpos/v1/customers/' + id )
					.then( function ( d ) { self.detailLoading = false; self.selected = d; } )
					.catch( function () { self.detailLoading = false; self.error = 'No se pudo abrir el cliente.'; } );
			},

			closeDetail: function () { this.selected = null; },

			saveEdit: function () {
				if ( ! this.selected || this.saveBusy ) { return; }
				var self = this;
				var c = this.selected;
				this.saveBusy = true;
				this.saveError = '';
				this.saveConflict = null;
				sendJSON( API.root + 'hmpos/v1/customers/' + c.id, 'PATCH', {
					name: c.name, email: c.email, doc: c.doc, iva: c.iva,
					phone: c.phone || '', birthdate: c.birthdate || ''
				} ).then( function ( res ) {
					self.saveBusy = false;
					if ( res.status === 409 ) { self.saveConflict = conflictCustomer( res ); self.saveError = res.data.message || 'Ya existe otro cliente con esos datos.'; return; }
					if ( ! res.ok ) { self.saveError = ( res.data && res.data.message ) || 'No se pudo guardar.'; return; }
					self.selected = res.data;
					// Refresca la fila en la lista sin recargar todo.
					var i = self.items.findIndex( function ( x ) { return x.id === res.data.id; } );
					if ( i !== -1 ) { self.items[ i ] = { id: res.data.id, name: res.data.name, email: res.data.email, doc: res.data.doc, doc_norm: res.data.doc_norm, iva: res.data.iva, stats: res.data.stats }; }
				} ).catch( function () { self.saveBusy = false; self.saveError = 'No se pudo guardar.'; } );
			},

			/* ---------- alta ---------- */
			openCreate: function () {
				this.createError = '';
				this.createConflict = null;
				this.newCust = { name: '', email: '', doc: '', phone: '', birthdate: '', iva: 'Consumidor Final' };
				this.createOpen = true;
				var self = this;
				this.$nextTick( function () { if ( self.$refs.newName ) { self.$refs.newName.focus(); } } );
			},

			closeCreate: function () { this.createOpen = false; },

			submitCreate: function () {
				if ( this.createBusy ) { return; }
				var p = this.newCust;
				if ( p.email === '' && p.doc === '' ) { this.createError = 'Cargá al menos email o DNI.'; return; }
				var self = this;
				this.createBusy = true;
				this.createError = '';
				this.createConflict = null;
				sendJSON( API.root + 'hmpos/v1/customers', 'POST', {
					name: p.name.trim(), email: p.email.trim(), doc: p.doc.trim(), iva: p.iva,
					phone: ( p.phone || '' ).trim(), birthdate: p.birthdate || ''
				} ).then( function ( res ) {
					self.createBusy = false;
					if ( res.status === 409 ) { self.createConflict = conflictCustomer( res ); self.createError = res.data.message || 'Ya existe un cliente con esos datos.'; return; }
					if ( ! res.ok ) { self.createError = ( res.data && res.data.message ) || 'No se pudo crear.'; return; }
					self.createOpen = false;
					self.load( 1 );          // recarga con el nuevo arriba (orden por registro DESC)
					self.open( res.data.id ); // abre su ficha
				} ).catch( function () { self.createBusy = false; self.createError = 'No se pudo crear.'; } );
			},

			// Abre el cliente existente ofrecido en un conflicto (alta o edición).
			useConflict: function ( c ) {
				if ( ! c ) { return; }
				this.createOpen = false;
				this.createConflict = null;
				this.saveConflict = null;
				this.open( c.id );
			},

			/* ---------- historial ---------- */
			reprint: function ( o ) {
				if ( o && o.print_url ) { window.open( o.print_url, '_blank', 'noopener' ); }
			}
		};
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'hmposCustomers', hmposCustomers );
	} );
}() );
