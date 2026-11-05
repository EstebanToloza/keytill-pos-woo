/* ============================================================
   Keytill POS — pos-products.js
   Pestaña "Productos" de la consola. Componente Alpine independiente
   (keytillProducts) montado en su vista; lee $store.pos para saber si
   está activa y si el usuario puede gestionar (canManage).
   Edición rápida inline de precio / stock / activo (solo gestión) +
   alta simple. Variaciones se expanden por fila (lazy). Imágenes,
   variaciones nuevas y categorías nuevas se gestionan en el backend.
   ============================================================ */
( function () {
	'use strict';

	var API = window.wpApiSettings || { root: '/wp-json/', nonce: '' };

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

	// ¿La respuesta falló por nonce vencido? («Ha fallado la comprobación de la cookie»).
	function isNonceError( res ) {
		return res && res.status === 403 && res.data && res.data.code === 'rest_cookie_invalid_nonce';
	}

	function doSend( url, method, body ) {
		return fetch( url, {
			method: method,
			headers: { 'X-WP-Nonce': API.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json().then( function ( d ) { return { ok: r.ok, status: r.status, data: d }; } );
		} );
	}

	// Mensaje claro cuando la sesión de WordPress caducó.
	var SESSION_MSG = 'Tu sesión expiró. Recargá la página (F5) y volvé a iniciar sesión.';
	function withSessionMsg( res ) {
		if ( isNonceError( res ) ) { res.data = res.data || {}; res.data.message = SESSION_MSG; }
		return res;
	}

	// Envía; si el nonce venció, lo refresca una vez y reintenta.
	function sendJSON( url, method, body ) {
		return doSend( url, method, body ).then( function ( res ) {
			if ( ! isNonceError( res ) || typeof window.keytillRefreshNonce !== 'function' ) { return res; }
			return window.keytillRefreshNonce( true ).then( function ( ok ) {
				if ( ! ok ) { return withSessionMsg( res ); } // sesión expirada de verdad.
				return doSend( url, method, body ).then( withSessionMsg );
			} );
		} );
	}

	// Formato de precio: lo provee pos.js según la config de WooCommerce.
	function money( n ) {
		return window.keytillMoney ? window.keytillMoney( n ) : String( Number( n ) || 0 );
	}

	function keytillProducts() {
		return {
			items: [],
			total: 0,
			pages: 1,
			page: 1,
			perPage: 20,
			filters: { search: '', category: '', status: '', stock: '' },
			meta: { categories: [] },
			loading: false,
			loaded: false,
			error: '',
			expanded: {},        // id -> { loading, error, rows: [] }
			_searchTimer: null,
			// selección para etiquetas en lote: id -> { id, title, barcode, qty }
			labelSel: {},
			labelsBatchOpen: false,
			// asignación de sucursal en lote
			selMode: 'labels',      // 'labels' | 'branch': para qué se selecciona
			branchSel: {},          // id -> { id, name } (selección manual)
			branchAll: false,       // asignar a TODOS los del filtro actual
			branchTarget: '',       // sucursal elegida ('' nada, '__none__' = quitar)
			branchBusy: false,
			// editor (alta + edición completa de simples)
			editorOpen: false,
			editorMode: 'create',   // create | edit
			editorBusy: false,
			editorError: '',
			uploadBusy: false,
			form: null,
			// sucursales activas, para el select del editor y el stock por sucursal
			branches: ( window.keytillConfig || {} ).branches || [],
			// stock por sucursal de una variación (popover) y diálogo de "mover stock"
			stockPop: { open: false, id: 0, name: '', draft: {}, busy: false, error: '', legacy: false, current: 0 },
			moveBox: { open: false, id: 0, name: '', from: '', to: '', qty: '', busy: false, error: '' },
			// atributos globales (pa_*) del sitio, para el editor de variables
			attributes: [],
			attributesLoaded: false,
			newAttrName: '',       // input "crear atributo nuevo"
			pickAttr: '',          // select "agregar atributo existente"
			genBusy: false,
			genMsg: '',

			get canManage() { return !! this.$store.pos.canManage; },

			init: function () {
				var self = this;
				this._onView = function ( e ) { if ( e.detail === 'productos' ) { self.ensureLoaded(); } };
				window.addEventListener( 'keytill:view', this._onView );
				if ( this.$store.pos.view === 'productos' ) { this.ensureLoaded(); }
			},

			money: money,

			ensureLoaded: function () {
				if ( this.loaded ) { return; }
				this.loaded = true;
				this.loadMeta();
				this.load( 1 );
			},

			loadMeta: function () {
				var self = this;
				getJSON( API.root + 'keytill/v1/admin/products/meta' )
					.then( function ( d ) { if ( d && d.categories ) { self.meta = d; } } )
					.catch( function () {} );
			},

			cleanFilters: function () {
				var f = this.filters, o = {};
				[ 'search', 'category', 'status', 'stock' ].forEach( function ( k ) {
					if ( f[ k ] !== '' && f[ k ] != null ) { o[ k ] = f[ k ]; }
				} );
				return o;
			},

			load: function ( page ) {
				var self = this;
				this.loading = true;
				this.error = '';
				this.expanded = {};
				var params = Object.assign( { page: page || 1, per_page: this.perPage }, this.cleanFilters() );
				getJSON( API.root + 'keytill/v1/admin/products?' + qs( params ) )
					.then( function ( d ) {
						self.loading = false;
						self.items = ( ( d && d.items ) || [] ).map( self.decorate );
						self.total = ( d && d.total ) || 0;
						self.pages = ( d && d.pages ) || 1;
						self.page = ( d && d.page ) || 1;
					} )
					.catch( function () { self.loading = false; self.error = 'No se pudo cargar el catálogo.'; } );
			},

			// Guarda los valores originales para detectar cambios + estado de fila.
			decorate: function ( r ) {
				r._price0 = r.regular_price;
				r._stock0 = ( r.stock_qty === null || r.stock_qty === undefined ) ? '' : String( r.stock_qty );
				r._stockStr = r._stock0;
				r._sku0 = ( r.sku === null || r.sku === undefined ) ? '' : String( r.sku );
				r._barcode0 = ( r.barcode === null || r.barcode === undefined ) ? '' : String( r.barcode );
				r._branch0 = ( r.branch_id === null || r.branch_id === undefined ) ? '' : String( r.branch_id );
				r._saving = false;
				r._saved = false;
				return r;
			},

			apply: function () { this.load( 1 ); },

			onSearch: function () {
				var self = this;
				clearTimeout( this._searchTimer );
				this._searchTimer = setTimeout( function () { self.load( 1 ); }, 250 );
			},

			goPage: function ( p ) {
				if ( p < 1 || p > this.pages ) { return; }
				this.load( p );
			},

			/* ---------- variaciones (expand lazy) ---------- */
			toggleExpand: function ( r ) {
				if ( r.type !== 'variable' ) { return; }
				if ( this.expanded[ r.id ] ) {
					delete this.expanded[ r.id ];
					return;
				}
				var self = this;
				this.expanded[ r.id ] = { loading: true, error: '', rows: [] };
				getJSON( API.root + 'keytill/v1/admin/products/' + r.id )
					.then( function ( d ) {
						var rows = ( ( d && d.variations ) || [] ).map( self.decorate );
						self.expanded[ r.id ] = { loading: false, error: '', rows: rows };
					} )
					.catch( function () { self.expanded[ r.id ] = { loading: false, error: 'No se pudieron cargar las variaciones.', rows: [] }; } );
			},

			/* ---------- edición rápida ---------- */
			// Avisa a la vista de venta que el catálogo cambió (así refresca su copia
			// en memoria en vez de mostrar datos viejos por el throttle de 30 s).
			_catalogDirty: function () {
				try { window.dispatchEvent( new CustomEvent( 'keytill:catalog-dirty' ) ); } catch ( e ) {}
			},

			saveField: function ( r, field ) {
				if ( ! this.canManage || r._saving ) { return; }
				var body = {};
				if ( field === 'regular_price' ) {
					if ( String( r.regular_price ) === String( r._price0 ) ) { return; }
					body.regular_price = r.regular_price;
				} else if ( field === 'stock_quantity' ) {
					if ( String( r._stockStr ) === String( r._stock0 ) ) { return; }
					body.stock_quantity = r._stockStr;
				} else if ( field === 'sku' ) {
					if ( String( r.sku || '' ) === String( r._sku0 || '' ) ) { return; }
					body.sku = r.sku || '';
				} else if ( field === 'barcode' ) {
					if ( String( r.barcode || '' ) === String( r._barcode0 || '' ) ) { return; }
					body.barcode = r.barcode || '';
				} else if ( field === 'branch_id' ) {
					if ( String( r.branch_id || '' ) === String( r._branch0 || '' ) ) { return; }
					var chosen = r.branch_id || '';
					if ( chosen && this.branches.length && ! r.branch_managed ) {
						// Atajo: elegir una sucursal en un ítem NO distribuido = "todo el
						// stock vive en esta sucursal". Pasa a distribuido con el 100% en
						// la elegida (0 en el resto). El número que se ve en la casilla de
						// stock es el total que se reparte.
						var raw   = ( r._stockStr !== undefined && r._stockStr !== '' ) ? r._stockStr : r.stock_qty;
						var total = parseInt( raw, 10 );
						if ( isNaN( total ) || total < 0 ) { total = 0; }
						var map = this.normBranchStock( {} );
						map[ chosen ] = total;
						body.branch_stock = map;
					} else {
						body.branch_id = chosen; // '' limpia la etiqueta; sigue legacy
					}
				}
				this._patch( r, body );
			},

			toggleActive: function ( r ) {
				if ( ! this.canManage || r._saving || r.is_variation ) { return; }
				this._patch( r, { status: r.active ? 'draft' : 'publish' } );
			},

			_patch: function ( r, body ) {
				var self = this;
				r._saving = true;
				r._saved = false;
				sendJSON( API.root + 'keytill/v1/admin/products/' + r.id, 'PATCH', body )
					.then( function ( res ) {
						r._saving = false;
						if ( ! res.ok ) {
							self.error = ( res.data && res.data.message ) || 'No se pudo guardar.';
							self.revert( r );
							return;
						}
						// Aplica los valores devueltos por el server (fuente de verdad).
						self.applyRow( r, res.data );
						self._catalogDirty();
						r._saved = true;
						setTimeout( function () { r._saved = false; }, 1200 );
					} )
					.catch( function () { r._saving = false; self.error = 'No se pudo guardar.'; self.revert( r ); } );
			},

			applyRow: function ( r, d ) {
				if ( ! d ) { return; }
				if ( 'regular_price' in d ) { r.regular_price = d.regular_price; r._price0 = d.regular_price; }
				if ( 'stock_qty' in d ) {
					r.stock_qty = d.stock_qty;
					r._stockStr = ( d.stock_qty === null || d.stock_qty === undefined ) ? '' : String( d.stock_qty );
					r._stock0 = r._stockStr;
				}
				if ( 'sku' in d ) { r.sku = d.sku; r._sku0 = ( d.sku === null || d.sku === undefined ) ? '' : String( d.sku ); }
				if ( 'barcode' in d ) { r.barcode = d.barcode; r._barcode0 = ( d.barcode === null || d.barcode === undefined ) ? '' : String( d.barcode ); }
				if ( 'branch_id' in d ) { r.branch_id = d.branch_id; r._branch0 = ( d.branch_id === null || d.branch_id === undefined ) ? '' : String( d.branch_id ); }
				if ( 'branch' in d ) { r.branch = d.branch; }
				if ( 'branch_stock' in d ) { r.branch_stock = d.branch_stock; }
				if ( 'stock_total' in d ) { r.stock_total = d.stock_total; }
				if ( 'branch_managed' in d ) { r.branch_managed = d.branch_managed; }
				if ( 'manage_stock' in d ) { r.manage_stock = d.manage_stock; }
				if ( 'in_stock' in d ) { r.in_stock = d.in_stock; }
				if ( 'active' in d ) { r.active = d.active; r.status = d.status; }
				if ( 'price_html' in d ) { r.price_html = d.price_html; }
			},

			revert: function ( r ) {
				r.regular_price = r._price0;
				r._stockStr = r._stock0;
				r.sku = r._sku0;
				r.barcode = r._barcode0;
			},

			// Cantidad sugerida de etiquetas: el stock del producto si está gestionado y
			// es positivo; si no, 30 por defecto. Acotada a 1..1000.
			labelDefaultQty: function ( r ) {
				var s = ( r && r.stock_qty !== null && r.stock_qty !== undefined ) ? parseInt( r.stock_qty, 10 ) : NaN;
				if ( isNaN( s ) || s <= 0 ) { return 30; }
				return Math.min( 1000, s );
			},

			// Abre las etiquetas imprimibles del producto/variación (pide la cantidad,
			// pre-rellenada con el stock si está definido).
			openLabels: function ( r ) {
				if ( ! r || ! r.label_url ) { return; }
				if ( ! r.barcode ) { this.error = 'Cargá primero el código de barras.'; return; }
				var n = window.prompt( '¿Cuántas etiquetas querés generar?', String( this.labelDefaultQty( r ) ) );
				if ( n === null ) { return; }
				var qty = Math.max( 1, Math.min( 1000, parseInt( n, 10 ) || 0 ) );
				window.open( r.label_url + '&qty=' + qty, '_blank' );
			},

			/* ---------- etiquetas en lote (multi-selección) ---------- */

			// ¿Se puede etiquetar esta fila? (tiene código de barras cargado).
			labelable: function ( r ) { return !! ( r && r.barcode ); },

			// ¿Está seleccionada?
			isLabelSel: function ( id ) { return !! this.labelSel[ id ]; },

			get labelSelCount() { return Object.keys( this.labelSel ).length; },

			// Marca/desmarca una fila (producto simple o variación). `title` es el
			// texto legible para el modal (nombre + atributos de la variación).
			toggleLabelSel: function ( r, title ) {
				if ( ! this.labelable( r ) ) { return; }
				if ( this.labelSel[ r.id ] ) {
					delete this.labelSel[ r.id ];
				} else {
					this.labelSel[ r.id ] = { id: r.id, title: title || r.name || ( '#' + r.id ), barcode: r.barcode, qty: this.labelDefaultQty( r ) };
				}
			},

			clearLabelSel: function () { this.labelSel = {}; },
			removeLabelSel: function ( id ) { delete this.labelSel[ id ]; },

			/* ---------- sucursal en lote ---------- */
			setSelMode: function ( m ) { this.selMode = ( m === 'branch' ) ? 'branch' : 'labels'; },
			isBranchSel: function ( id ) { return !! this.branchSel[ id ]; },
			get branchSelCount() { return Object.keys( this.branchSel ).length; },
			// Tilda/destilda un ítem (producto simple o variación). Si venía "todos
			// del filtro", pasa a selección manual.
			toggleBranchSel: function ( r ) {
				if ( ! r ) { return; }
				if ( this.branchAll ) { this.branchAll = false; this.branchSel = {}; }
				if ( this.branchSel[ r.id ] ) { delete this.branchSel[ r.id ]; }
				else { this.branchSel[ r.id ] = { id: r.id, name: r.name || r.label || ( '#' + r.id ) }; }
			},
			// Variaciones de un producto ya expandido (todas: la sucursal no depende
			// del código de barras). Para el "seleccionar todas".
			branchVars: function ( r ) {
				var ex = this.expanded[ r.id ];
				return ( ex && ex.rows ) ? ex.rows : [];
			},
			allBranchVarsSelected: function ( r ) {
				var vs = this.branchVars( r );
				if ( ! vs.length ) { return false; }
				var self = this;
				return vs.every( function ( v ) { return !! self.branchSel[ v.id ]; } );
			},
			toggleAllBranchVars: function ( r ) {
				var vs = this.branchVars( r );
				if ( ! vs.length ) { return; }
				if ( this.branchAll ) { this.branchAll = false; this.branchSel = {}; }
				var self = this;
				var all = this.allBranchVarsSelected( r );
				vs.forEach( function ( v ) {
					if ( all ) { delete self.branchSel[ v.id ]; }
					else { self.branchSel[ v.id ] = { id: v.id, name: ( r.name || '' ) + ' — ' + ( v.label || '' ) }; }
				} );
			},
			selectAllBranchFilter: function () { this.branchAll = true; this.branchSel = {}; },
			clearBranchSel: function () { this.branchAll = false; this.branchSel = {}; this.branchTarget = ''; },
			assignBranch: function () {
				if ( this.branchBusy ) { return; }
				if ( ! this.branchTarget ) { this.error = 'Elegí una sucursal.'; return; }
				var count = this.branchAll ? this.total : this.branchSelCount;
				if ( count < 1 ) { this.error = 'No seleccionaste ningún producto.'; return; }
				var clear = ( this.branchTarget === '__none__' );
				var target = this.branchTarget;
				var bname = clear ? '' : ( ( this.branches.filter( function ( b ) { return b.id === target; } )[ 0 ] || {} ).name || '' );
				var msg = clear
					? ( '¿Quitar la sucursal de ' + count + ' producto(s)?' )
					: ( '¿Asignar ' + count + ' producto(s) a la sucursal "' + bname + '"?' );
				if ( ! window.confirm( msg ) ) { return; }
				var self = this;
				var body = { branch_id: clear ? '' : target };
				if ( this.branchAll ) { body.all = true; Object.assign( body, this.cleanFilters() ); }
				else { body.ids = Object.keys( this.branchSel ).map( function ( k ) { return parseInt( k, 10 ); } ); }
				this.branchBusy = true;
				this.error = '';
				sendJSON( API.root + 'keytill/v1/admin/products/bulk-branch', 'POST', body ).then( function ( res ) {
					self.branchBusy = false;
					if ( ! res.ok ) { self.error = ( res.data && res.data.message ) || 'No se pudo asignar la sucursal.'; return; }
					self._catalogDirty();
					self.clearBranchSel();
					self.load( self.page ); // refresca la columna Sucursal
				} ).catch( function () { self.branchBusy = false; self.error = 'No se pudo asignar la sucursal.'; } );
			},

			// Variaciones de un producto (ya expandido) que se pueden etiquetar
			// (tienen código de barras). Para el "seleccionar todas".
			labelSelectableVars: function ( r ) {
				var ex = this.expanded[ r.id ];
				if ( ! ex || ! ex.rows ) { return []; }
				return ex.rows.filter( function ( v ) { return !! v.barcode; } );
			},
			// ¿Están TODAS las variaciones etiquetables seleccionadas?
			allVarsSelected: function ( r ) {
				var vs = this.labelSelectableVars( r );
				if ( ! vs.length ) { return false; }
				var self = this;
				return vs.every( function ( v ) { return !! self.labelSel[ v.id ]; } );
			},
			// Marca/desmarca TODAS las variaciones etiquetables del producto.
			toggleAllVars: function ( r ) {
				var vs = this.labelSelectableVars( r );
				if ( ! vs.length ) { return; }
				var self = this;
				var all = this.allVarsSelected( r );
				vs.forEach( function ( v ) {
					if ( all ) {
						delete self.labelSel[ v.id ];
					} else if ( ! self.labelSel[ v.id ] ) {
						self.labelSel[ v.id ] = { id: v.id, title: ( r.name || '' ) + ' — ' + ( v.label || '' ), barcode: v.barcode, qty: self.labelDefaultQty( v ) };
					}
				} );
			},

			// Filas del modal, ordenadas por título para una lista estable.
			labelSelRows: function () {
				return Object.keys( this.labelSel ).map( function ( k ) { return this.labelSel[ k ]; }.bind( this ) )
					.sort( function ( a, b ) { return String( a.title ).localeCompare( String( b.title ) ); } );
			},

			openLabelsBatch: function () {
				if ( this.labelSelCount === 0 ) { return; }
				this.labelsBatchOpen = true;
			},
			closeLabelsBatch: function () { this.labelsBatchOpen = false; },

			// Total de etiquetas a imprimir (suma de cantidades).
			labelSelTotal: function () {
				return this.labelSelRows().reduce( function ( s, it ) { return s + ( Math.max( 1, Math.min( 1000, parseInt( it.qty, 10 ) || 0 ) ) ); }, 0 );
			},

			// Genera las etiquetas del lote: POST (nuevo tab) con items[<id>]=<qty>.
			confirmLabelsBatch: function () {
				var cfg = window.keytillConfig || {};
				if ( ! cfg.labelsBatch || ! cfg.labelsNonce ) { this.error = 'No se pudo iniciar la impresión.'; return; }
				var rows = this.labelSelRows();
				if ( ! rows.length ) { return; }
				var form = document.createElement( 'form' );
				form.method = 'POST';
				form.action = cfg.labelsBatch;
				form.target = '_blank';
				var add = function ( name, value ) {
					var i = document.createElement( 'input' );
					i.type = 'hidden'; i.name = name; i.value = value;
					form.appendChild( i );
				};
				add( '_wpnonce', cfg.labelsNonce );
				rows.forEach( function ( it ) {
					var q = Math.max( 1, Math.min( 1000, parseInt( it.qty, 10 ) || 0 ) );
					add( 'items[' + it.id + ']', q );
				} );
				document.body.appendChild( form );
				form.submit();
				document.body.removeChild( form );
				this.labelsBatchOpen = false;
			},

			/* ---------- editor (alta + edición completa) ---------- */
			blankForm: function () {
				return {
					id: 0, type: 'simple', name: '', sku: '', barcode: '', label_url: '', category_id: '',
					branch_id: '',
					regular_price: '', sale_price: '', stock_quantity: '',
					branch_stock: this.normBranchStock( {} ), // stock por sucursal (simples)
					branchManaged: false, // ¿ya distribuido por sucursal? (si no, stock único)
					description: '', short_description: '', active: true,
					image_id: 0, image_url: '',
					gallery: [],        // galería del producto: [{ id, url }]
					attrRows: [],       // atributos elegidos para variar
					variations: []      // grilla de variaciones (editable)
				};
			},

			// Devuelve un mapa {branch_id: qty} con TODAS las sucursales activas
			// presentes (0 si no venían), para poder pintar/editar cada casilla.
			normBranchStock: function ( map ) {
				var m = map || {};
				var out = {};
				( this.branches || [] ).forEach( function ( b ) {
					out[ b.id ] = ( m[ b.id ] === undefined || m[ b.id ] === null ) ? 0 : parseInt( m[ b.id ], 10 ) || 0;
				} );
				return out;
			},

			// Suma de un mapa de stock por sucursal.
			branchStockTotal: function ( map ) {
				var t = 0, m = map || {};
				Object.keys( m ).forEach( function ( k ) { t += parseInt( m[ k ], 10 ) || 0; } );
				return t;
			},

			// Vista rápida de sucursal/stock para una fila del listado (producto
			// simple o variación), como lista de segmentos [{txt, cls}]. Distribuido:
			// desglose por sucursal "Centro: 3 · Avellaneda: 5" (las que están en 0 o
			// negativo van en rojo, cls 'bs-out'). Sin distribuir: la sucursal asignada
			// ("Sucursal: Centro") o "Sin sucursal asignada". [] si no hay sucursales.
			branchInfo: function ( row ) {
				if ( ! this.branches.length ) { return []; }
				if ( row && row.branch_managed ) {
					var map = row.branch_stock || {};
					return this.branches.map( function ( b ) {
						var v = map[ b.id ];
						v = ( v === undefined || v === null ) ? 0 : parseInt( v, 10 );
						if ( isNaN( v ) ) { v = 0; }
						return { txt: b.name + ': ' + v, cls: ( v <= 0 ) ? 'bs-out' : '' };
					} );
				}
				if ( row && row.branch ) { return [ { txt: 'Sucursal: ' + row.branch, cls: '' } ]; }
				return [ { txt: 'Sin sucursal asignada', cls: 'bs-none' } ];
			},

			// "Nuevo producto".
			openCreate: function () {
				if ( ! this.canManage ) { return; }
				this.editorMode = 'create';
				this.editorError = '';
				this.genMsg = '';
				this.form = this.blankForm();
				// Producto nuevo: si hay sucursales, se define el stock por sucursal
				// directo (no hay stock viejo que preservar).
				this.form.branchManaged = this.branches.length > 0;
				this.editorOpen = true;
				this.ensureAttributes();
				var self = this;
				this.$nextTick( function () { self.rteFill(); if ( self.$refs.edName ) { self.$refs.edName.focus(); } } );
			},

			// Editar un producto existente (carga el detalle completo).
			openEditor: function ( row ) {
				if ( ! this.canManage ) { return; }
				var self = this;
				this.editorMode = 'edit';
				this.editorError = '';
				this.genMsg = '';
				this.form = this.blankForm();
				this.form.id = row.id;
				this.form.name = row.name;
				this.form.type = row.type;
				this.editorOpen = true;
				this.editorBusy = true;
				this.ensureAttributes();
				getJSON( API.root + 'keytill/v1/admin/products/' + row.id )
					.then( function ( d ) {
						self.editorBusy = false;
						if ( ! d || d.id === undefined ) { self.editorError = 'No se pudo cargar el producto.'; return; }
						self.form = {
							id: d.id, type: d.type, name: d.name || '', sku: d.sku || '', barcode: d.barcode || '', label_url: d.label_url || '',
							category_id: d.category_id || '', branch_id: d.branch_id || '', regular_price: d.regular_price || '',
							sale_price: d.sale_price || '',
							stock_quantity: ( d.stock_qty === null || d.stock_qty === undefined ) ? '' : String( d.stock_qty ),
							branch_stock: self.normBranchStock( d.branch_stock || {} ),
							branchManaged: !! d.branch_managed,
							description: d.description || '', short_description: d.short_description || '',
							active: !! d.active, image_id: d.image_id || 0, image_url: d.image_full || d.image || '',
							gallery: ( d.gallery || [] ).map( function ( g ) { return { id: g.id, url: g.thumb || g.url }; } ),
							attrRows: [],
							variations: ( d.variations || [] ).map( self.decorate )
						};
						if ( d.type === 'variable' ) {
							self.buildAttrRows( d.variation_attributes || [] );
						}
						self.$nextTick( function () { self.rteFill(); } );
					} )
					.catch( function () { self.editorBusy = false; self.editorError = 'No se pudo cargar el producto.'; } );
			},

			closeEditor: function () { this.editorOpen = false; },

			/* ---------- stock por sucursal: popover de variación + mover ---------- */
			// Abre el popover de una variación con su stock por sucursal editable.
			// Si la variación es "legacy" (aún no distribuida), arranca en cero por
			// sucursal y muestra su stock actual como referencia para repartirlo.
			openStockPop: function ( v ) {
				if ( ! this.canManage ) { return; }
				var legacy = ! v.branch_managed;
				this.stockPop = {
					open: true, id: v.id, name: v.label || '',
					draft: legacy ? this.normBranchStock( {} ) : this.normBranchStock( v.branch_stock ),
					legacy: legacy,
					current: ( v.stock_qty === null || v.stock_qty === undefined ) ? 0 : v.stock_qty,
					busy: false, error: ''
				};
			},
			closeStockPop: function () { this.stockPop.open = false; },
			// Total del borrador del popover.
			stockPopTotal: function () { return this.branchStockTotal( this.stockPop.draft ); },
			// Guarda el stock por sucursal de la variación (PATCH branch_stock).
			saveStockPop: function () {
				var self = this, sp = this.stockPop;
				if ( sp.busy ) { return; }
				sp.busy = true; sp.error = '';
				sendJSON( API.root + 'keytill/v1/admin/products/' + sp.id, 'PATCH', { branch_stock: sp.draft } ).then( function ( res ) {
					sp.busy = false;
					if ( ! res.ok ) { sp.error = ( res.data && res.data.message ) || 'No se pudo guardar.'; return; }
					var v = ( self.form.variations || [] ).find( function ( x ) { return x.id === sp.id; } );
					if ( v ) { self.applyRow( v, res.data ); }
					self._catalogDirty();
					sp.open = false;
				} ).catch( function () { sp.busy = false; sp.error = 'No se pudo guardar.'; } );
			},

			// Abre el diálogo de "mover stock" para un producto/variación.
			openMove: function ( id, name ) {
				if ( ! this.canManage || this.branches.length < 2 ) { return; }
				this.moveBox = { open: true, id: id, name: name || '', from: this.branches[ 0 ].id, to: this.branches[ 1 ].id, qty: '', busy: false, error: '' };
			},
			closeMove: function () { this.moveBox.open = false; },
			doMove: function () {
				var self = this, mb = this.moveBox;
				if ( mb.busy ) { return; }
				var qty = parseInt( mb.qty, 10 ) || 0;
				if ( mb.from === mb.to ) { mb.error = 'Elegí sucursales distintas.'; return; }
				if ( qty <= 0 ) { mb.error = 'Cantidad inválida.'; return; }
				mb.busy = true; mb.error = '';
				sendJSON( API.root + 'keytill/v1/admin/products/' + mb.id + '/move-stock', 'POST', { from: mb.from, to: mb.to, qty: qty } ).then( function ( res ) {
					mb.busy = false;
					if ( ! res.ok ) { mb.error = ( res.data && res.data.message ) || 'No se pudo mover el stock.'; return; }
					// Refresca donde corresponda: form simple, fila de variación y/o popover.
					if ( self.form && self.form.id === mb.id && self.form.type === 'simple' ) {
						self.form.branch_stock = self.normBranchStock( res.data.branch_stock );
					}
					var v = ( self.form && self.form.variations || [] ).find( function ( x ) { return x.id === mb.id; } );
					if ( v ) { self.applyRow( v, res.data ); }
					if ( self.stockPop.open && self.stockPop.id === mb.id ) { self.stockPop.draft = self.normBranchStock( res.data.branch_stock ); }
					self._catalogDirty();
					mb.open = false;
				} ).catch( function () { mb.busy = false; mb.error = 'No se pudo mover el stock.'; } );
			},

			/* ---------- editor de texto enriquecido (descripciones) ---------- */
			// Carga el HTML guardado en las áreas editables (al abrir el editor).
			rteFill: function () {
				if ( this.$refs.rteShort ) { this.$refs.rteShort.innerHTML = this.form.short_description || ''; }
				if ( this.$refs.rteDesc ) { this.$refs.rteDesc.innerHTML = this.form.description || ''; }
			},
			// Aplica un comando de formato a la descripción enfocada y sincroniza.
			rteCmd: function ( cmd, which ) {
				document.execCommand( cmd, false, null );
				this.rteSync( which );
			},
			rteLink: function ( which ) {
				var url = window.prompt( 'URL del enlace (https://…)', 'https://' );
				if ( url && url !== 'https://' ) {
					document.execCommand( 'createLink', false, url );
					this.rteSync( which );
				}
			},
			// Vuelca el HTML del área editable al campo del formulario.
			rteSync: function ( which ) {
				if ( 'short' === which && this.$refs.rteShort ) { this.form.short_description = this.$refs.rteShort.innerHTML; }
				if ( 'desc' === which && this.$refs.rteDesc ) { this.form.description = this.$refs.rteDesc.innerHTML; }
			},

			/* ---------- atributos globales + grilla de variaciones ---------- */

			// Carga (una vez) el catálogo de atributos globales del sitio.
			ensureAttributes: function () {
				if ( this.attributesLoaded ) { return; }
				var self = this;
				getJSON( API.root + 'keytill/v1/admin/attributes' )
					.then( function ( d ) {
						self.attributes = ( d && d.attributes ) || [];
						self.attributesLoaded = true;
					} )
					.catch( function () {} );
			},

			// Reconstruye las filas de atributos del editor a partir de los del
			// producto (marca los términos ya usados en variación).
			buildAttrRows: function ( varAttrs ) {
				var self = this;
				var rows = [];
				varAttrs.forEach( function ( va ) {
					var cat = self.attributes.filter( function ( a ) { return a.taxonomy === va.taxonomy; } )[ 0 ];
					if ( ! cat ) { return; }
					rows.push( self.makeAttrRow( cat, va.term_ids || [] ) );
				} );
				this.form.attrRows = rows;
			},

			makeAttrRow: function ( cat, selectedIds ) {
				var sel = {};
				( selectedIds || [] ).forEach( function ( id ) { sel[ id ] = true; } );
				return {
					attrId: cat.id, taxonomy: cat.taxonomy, name: cat.name, slug: cat.slug,
					terms: ( cat.terms || [] ).map( function ( t ) {
						return { id: t.id, name: t.name, slug: t.slug, on: !! sel[ t.id ] };
					} ),
					newTerm: ''
				};
			},

			// Atributos del catálogo que todavía no están en el editor.
			availableAttrs: function () {
				var used = ( this.form.attrRows || [] ).map( function ( r ) { return r.taxonomy; } );
				return this.attributes.filter( function ( a ) { return used.indexOf( a.taxonomy ) === -1; } );
			},

			// Agrega una fila de atributo (existente) desde el select.
			addAttrRow: function () {
				var tax = this.pickAttr;
				this.pickAttr = '';
				if ( ! tax ) { return; }
				var cat = this.attributes.filter( function ( a ) { return a.taxonomy === tax; } )[ 0 ];
				if ( ! cat ) { return; }
				this.form.attrRows.push( this.makeAttrRow( cat, [] ) );
			},

			removeAttrRow: function ( idx ) { this.form.attrRows.splice( idx, 1 ); },

			// Crea un atributo global nuevo (pa_*) y lo agrega al editor.
			createAttribute: function () {
				var name = ( this.newAttrName || '' ).trim();
				if ( ! name || this.genBusy ) { return; }
				var self = this;
				this.genBusy = true;
				this.editorError = '';
				sendJSON( API.root + 'keytill/v1/admin/attributes', 'POST', { name: name } ).then( function ( res ) {
					self.genBusy = false;
					if ( ! res.ok ) { self.editorError = ( res.data && res.data.message ) || 'No se pudo crear el atributo.'; return; }
					var cat = res.data;
					// Actualiza/inserta en el catálogo.
					var i = self.attributes.findIndex( function ( a ) { return a.id === cat.id; } );
					if ( i === -1 ) { self.attributes.push( cat ); } else { self.attributes[ i ] = cat; }
					self.newAttrName = '';
					// Si no está ya en el editor, lo agrega.
					if ( self.form.attrRows.filter( function ( r ) { return r.taxonomy === cat.taxonomy; } ).length === 0 ) {
						self.form.attrRows.push( self.makeAttrRow( cat, [] ) );
					}
				} ).catch( function () { self.genBusy = false; self.editorError = 'No se pudo crear el atributo.'; } );
			},

			// Crea un término nuevo dentro de una fila de atributo.
			createTerm: function ( row ) {
				var name = ( row.newTerm || '' ).trim();
				if ( ! name || this.genBusy ) { return; }
				var self = this;
				this.genBusy = true;
				this.editorError = '';
				sendJSON( API.root + 'keytill/v1/admin/attributes/' + row.attrId + '/terms', 'POST', { name: name } ).then( function ( res ) {
					self.genBusy = false;
					if ( ! res.ok ) { self.editorError = ( res.data && res.data.message ) || 'No se pudo crear el valor.'; return; }
					var t = res.data;
					if ( row.terms.filter( function ( x ) { return x.id === t.id; } ).length === 0 ) {
						row.terms.push( { id: t.id, name: t.name, slug: t.slug, on: true } );
					}
					// Refleja en el catálogo para reusarlo en otros productos.
					var cat = self.attributes.filter( function ( a ) { return a.taxonomy === row.taxonomy; } )[ 0 ];
					if ( cat && cat.terms.filter( function ( x ) { return x.id === t.id; } ).length === 0 ) {
						cat.terms.push( { id: t.id, name: t.name, slug: t.slug } );
					}
					row.newTerm = '';
				} ).catch( function () { self.genBusy = false; self.editorError = 'No se pudo crear el valor.'; } );
			},

			// Genera la grilla de variaciones con los términos marcados.
			generateVariations: function ( prune ) {
				if ( ! this.form.id || this.genBusy ) { return; }
				var attrs = ( this.form.attrRows || [] ).map( function ( row ) {
					return { taxonomy: row.taxonomy, term_ids: row.terms.filter( function ( t ) { return t.on; } ).map( function ( t ) { return t.id; } ) };
				} ).filter( function ( a ) { return a.term_ids.length > 0; } );
				if ( ! attrs.length ) { this.editorError = 'Elegí al menos un atributo con valores.'; return; }
				var self = this;
				this.genBusy = true;
				this.genMsg = '';
				this.editorError = '';
				var body = { attributes: attrs };
				if ( prune ) { body.prune = true; }
				sendJSON( API.root + 'keytill/v1/admin/products/' + this.form.id + '/variations', 'POST', body ).then( function ( res ) {
					self.genBusy = false;
					if ( ! res.ok ) { self.editorError = ( res.data && res.data.message ) || 'No se pudieron generar las variaciones.'; return; }
					// El backend detectó variaciones que ya no corresponden a los
					// atributos actuales: pedimos confirmación antes de borrarlas.
					if ( res.data && res.data.needs_prune ) {
						var orphans = res.data.orphans || [];
						var names = orphans.slice( 0, 8 ).map( function ( o ) { return '• ' + ( o.label || ( '#' + o.id ) ); } ).join( '\n' );
						if ( orphans.length > 8 ) { names += '\n• …y ' + ( orphans.length - 8 ) + ' más'; }
						var msg = 'Hay ' + orphans.length + ' variación(es) que ya no corresponden a los atributos elegidos:\n\n' + names + '\n\n¿Borrarlas y regenerar?';
						if ( window.confirm( msg ) ) {
							self.generateVariations( true );
						} else {
							self.genMsg = 'No se generó nada: primero resolvé las variaciones que sobran.';
						}
						return;
					}
					self.form.variations = ( res.data.variations || [] ).map( self.decorate );
					var parts = [];
					if ( res.data.created > 0 ) { parts.push( res.data.created + ' nueva(s)' ); }
					if ( res.data.pruned > 0 ) { parts.push( res.data.pruned + ' borrada(s)' ); }
					self.genMsg = parts.length ? ( 'Variaciones: ' + parts.join( ', ' ) + '.' ) : 'Sin cambios.';
					self.syncRowVariations();
					self._catalogDirty();
				} ).catch( function () { self.genBusy = false; self.editorError = 'No se pudieron generar las variaciones.'; } );
			},

			// Borra el producto completo (va a la papelera de WordPress; recuperable).
			deleteProduct: function () {
				if ( this.genBusy || ! this.form.id ) { return; }
				if ( ! window.confirm( '¿Enviar el producto "' + ( this.form.name || '' ) + '" a la papelera?\n\nSe puede recuperar desde WordPress si fue un error.' ) ) { return; }
				var self = this;
				var id = this.form.id;
				this.genBusy = true;
				this.editorError = '';
				sendJSON( API.root + 'keytill/v1/admin/products/' + id, 'DELETE', {} ).then( function ( res ) {
					self.genBusy = false;
					if ( ! res.ok ) { self.editorError = ( res.data && res.data.message ) || 'No se pudo borrar el producto.'; return; }
					self.items = self.items.filter( function ( x ) { return x.id !== id; } );
					delete self.expanded[ id ];
					if ( self.labelSel[ id ] ) { delete self.labelSel[ id ]; }
					self._catalogDirty();
					self.closeEditor();
				} ).catch( function () { self.genBusy = false; self.editorError = 'No se pudo borrar el producto.'; } );
			},

			// Borra una variación (acotado a una variación puntual).
			deleteVariation: function ( v ) {
				if ( this.genBusy ) { return; }
				if ( ! window.confirm( '¿Borrar la variación "' + ( v.label || '' ) + '"?' ) ) { return; }
				var self = this;
				this.genBusy = true;
				sendJSON( API.root + 'keytill/v1/admin/products/' + v.id, 'DELETE', {} ).then( function ( res ) {
					self.genBusy = false;
					if ( ! res.ok ) { self.editorError = ( res.data && res.data.message ) || 'No se pudo borrar.'; return; }
					self.form.variations = self.form.variations.filter( function ( x ) { return x.id !== v.id; } );
					self.syncRowVariations();
					self._catalogDirty();
				} ).catch( function () { self.genBusy = false; self.editorError = 'No se pudo borrar.'; } );
			},

			// Refleja el conteo de variaciones y refresca el expand del listado.
			syncRowVariations: function () {
				var i = this.items.findIndex( function ( x ) { return x.id === this.form.id; }.bind( this ) );
				if ( i !== -1 ) { this.items[ i ].variations = this.form.variations.length; }
				if ( this.expanded[ this.form.id ] ) {
					this.expanded[ this.form.id ] = { loading: false, error: '', rows: this.form.variations.map( this.decorate ) };
				}
			},

			uploadImage: function ( ev ) {
				var file = ev.target.files && ev.target.files[ 0 ];
				if ( ! file ) { return; }
				var self = this;
				this.uploadBusy = true;
				this.editorError = '';
				var fd = new FormData();
				fd.append( 'file', file );
				fetch( API.root + 'keytill/v1/admin/media', {
					method: 'POST', headers: { 'X-WP-Nonce': API.nonce }, credentials: 'same-origin', body: fd
				} ).then( function ( r ) {
					return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } );
				} ).then( function ( res ) {
					self.uploadBusy = false;
					if ( ! res.ok ) { self.editorError = ( res.data && res.data.message ) || 'No se pudo subir la imagen.'; return; }
					self.form.image_id = res.data.id;
					self.form.image_url = res.data.url;
				} ).catch( function () { self.uploadBusy = false; self.editorError = 'No se pudo subir la imagen.'; } );
				ev.target.value = '';
			},

			removeImage: function () { this.form.image_id = 0; this.form.image_url = ''; },

			// Galería: sube uno o varios archivos y los agrega al final.
			uploadGallery: function ( ev ) {
				var files = ev.target.files ? Array.prototype.slice.call( ev.target.files ) : [];
				ev.target.value = '';
				if ( ! files.length ) { return; }
				var self = this;
				this.uploadBusy = true;
				this.editorError = '';
				// Sube en secuencia para preservar el orden elegido.
				var chain = Promise.resolve();
				files.forEach( function ( file ) {
					chain = chain.then( function () {
						var fd = new FormData();
						fd.append( 'file', file );
						return fetch( API.root + 'keytill/v1/admin/media', {
							method: 'POST', headers: { 'X-WP-Nonce': API.nonce }, credentials: 'same-origin', body: fd
						} ).then( function ( r ) {
							return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } );
						} ).then( function ( res ) {
							if ( ! res.ok ) { self.editorError = ( res.data && res.data.message ) || 'No se pudo subir una imagen.'; return; }
							self.form.gallery.push( { id: res.data.id, url: res.data.thumb || res.data.url } );
						} );
					} );
				} );
				chain.then( function () { self.uploadBusy = false; } )
					.catch( function () { self.uploadBusy = false; self.editorError = 'No se pudo subir una imagen.'; } );
			},

			removeGallery: function ( idx ) { this.form.gallery.splice( idx, 1 ); },

			saveEditor: function () {
				if ( this.editorBusy || ! this.form ) { return; }
				var f = this.form;
				if ( ! f.name.trim() ) { this.editorError = 'El nombre es obligatorio.'; return; }
				if ( f.type === 'simple' && f.regular_price === '' ) { this.editorError = 'El precio es obligatorio.'; return; }
				var self = this;
				this.editorBusy = true;
				this.editorError = '';
				var body = {
					name: f.name.trim(), sku: ( f.sku || '' ).trim(), category_id: f.category_id,
					branch_id: f.branch_id || '',
					description: f.description, short_description: f.short_description, image_id: f.image_id,
					gallery_ids: ( f.gallery || [] ).map( function ( g ) { return g.id; } ),
					status: f.active ? 'publish' : 'draft'
				};
				if ( f.type === 'simple' ) {
					body.regular_price = f.regular_price;
					body.sale_price = f.sale_price;
					body.barcode = ( f.barcode || '' );
					// Solo si el producto ya está DISTRIBUIDO por sucursal manda
					// branch_stock (fuente de verdad; el total nativo lo calcula el
					// server). Si es "legacy" (aún no distribuido) mantiene su stock
					// único — así editar el precio no le pisa el stock a cero.
					if ( this.branches.length && f.branchManaged ) {
						body.branch_stock = f.branch_stock || {};
					} else if ( this.branches.length && f.branch_id ) {
						// Atajo: sucursal elegida en un producto no distribuido = 100% ahí.
						var total = parseInt( f.stock_quantity, 10 );
						if ( isNaN( total ) || total < 0 ) { total = 0; }
						var map = this.normBranchStock( {} );
						map[ f.branch_id ] = total;
						body.branch_stock = map;
						delete body.branch_id; // deja de ser etiqueta informativa
					} else {
						body.stock_quantity = f.stock_quantity;
					}
				} else {
					body.type = 'variable';
				}
				var isCreate = this.editorMode === 'create';
				var url = isCreate ? API.root + 'keytill/v1/admin/products' : API.root + 'keytill/v1/admin/products/' + f.id;
				sendJSON( url, isCreate ? 'POST' : 'PATCH', body ).then( function ( res ) {
					self.editorBusy = false;
					if ( res.status === 409 ) { self.editorError = ( res.data && res.data.message ) || 'Ya existe otro producto con ese SKU.'; return; }
					if ( ! res.ok ) { self.editorError = ( res.data && res.data.message ) || 'No se pudo guardar.'; return; }
					self._catalogDirty();
					if ( isCreate ) {
						self.items.unshift( self.decorate( res.data ) );
						self.total += 1;
					} else {
						var i = self.items.findIndex( function ( x ) { return x.id === res.data.id; } );
						if ( i !== -1 ) { self.items[ i ] = self.decorate( res.data ); }
					}
					// Variable recién creado: no se cierra; se pasa a modo edición para
					// definir atributos y generar la grilla (segundo paso).
					if ( isCreate && f.type === 'variable' ) {
						self.editorMode = 'edit';
						self.form.id = res.data.id;
						self.genMsg = 'Producto creado. Ahora definí los atributos y generá las variaciones.';
						return;
					}
					self.editorOpen = false;
				} ).catch( function () { self.editorBusy = false; self.editorError = 'No se pudo guardar.'; } );
			}
		};
	}

	document.addEventListener( 'alpine:init', function () {
		window.Alpine.data( 'keytillProducts', keytillProducts );
	} );
}() );
