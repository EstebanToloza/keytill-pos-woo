/* ============================================================
   Keytill POS — pos.js
   Pantalla de venta en Alpine, portada del prototipo
   (reference/POS Venta.dc.html). Comportamiento fiel:
   campo de comando siempre enfocado, re-foco por evento tras cada
   acción, prefijo N*, carrito por teclado, cobro y formato es-AR.

   Extras "esenciales" únicamente: sin beep ni panel de actividad/reloj.
   Lookup híbrido: catálogo precargado (escaneo instantáneo) + re-sync
   al recuperar foco; el stock real se descuenta server-side al cobrar.
   ============================================================ */
( function () {
	'use strict';

	var API = window.wpApiSettings || { root: '/wp-json/', nonce: '' };
	var CONFIG = window.keytillConfig || { canManage: false };

	/* --------------------------------------------------------------
	   Keeper del nonce REST. La pantalla del POS queda abierta muchas
	   horas: el nonce `wp_rest` caduca (~12–24 h) y la primera escritura
	   fallaba con «Ha fallado la comprobación de la cookie». Refrescamos
	   el nonce proactivamente (al cargar, cada pocos minutos y al volver
	   a la pestaña) contra admin-ajax `rest-nonce`. Como todos los
	   componentes leen `window.wpApiSettings.nonce` (mismo objeto), con
	   actualizarlo acá se corrige en toda la app. Devuelve 0 si la sesión
	   expiró → avisamos para reloguear.
	   -------------------------------------------------------------- */
	var KEYTILLNonce = ( function () {
		var url = CONFIG.ajaxUrl || '/wp-admin/admin-ajax.php';
		var last = 0;
		var expired = false;
		function looksValid( s ) { return typeof s === 'string' && /^[a-f0-9]{8,64}$/i.test( s.trim() ); }
		function refresh( force ) {
			var now = Date.now();
			if ( ! force && now - last < 8000 ) { return Promise.resolve( false ); } // throttle
			last = now;
			return fetch( url + ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + 'action=rest-nonce', {
				credentials: 'same-origin',
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			} )
				.then( function ( r ) { return r.text(); } )
				.then( function ( t ) {
					t = ( t || '' ).trim();
					if ( looksValid( t ) ) {
						if ( window.wpApiSettings ) { window.wpApiSettings.nonce = t; }
						API.nonce = t;
						expired = false;
						return true;
					}
					// '0' o vacío: la sesión de WordPress caducó.
					expired = true;
					return false;
				} )
				.catch( function () { return false; } );
		}
		// Arranque + refresco periódico + al volver a la pestaña / foco.
		refresh( true );
		setInterval( function () { refresh( true ); }, 5 * 60 * 1000 );
		document.addEventListener( 'visibilitychange', function () { if ( ! document.hidden ) { refresh(); } } );
		window.addEventListener( 'focus', function () { refresh(); } );
		return {
			refresh: refresh,
			isExpired: function () { return expired; }
		};
	} )();
	window.keytillRefreshNonce = KEYTILLNonce.refresh;
	window.keytillNonceExpired = KEYTILLNonce.isExpired;

	// Normaliza para búsquedas (minúsculas, sin acentos).
	function norm( s ) {
		return ( s || '' ).toString().toLowerCase().normalize( 'NFD' ).replace( new RegExp( '[\\u0300-\\u036f]', 'g' ), '' );
	}

	// Medios de pago: vienen de Ajustes (localize). Efectivo siempre primero (medio
	// especial: cajón/vuelto/arqueo). Fallback a los 4 clásicos si no hay config.
	var METHODS = ( CONFIG.methods && CONFIG.methods.length )
		? CONFIG.methods
		: [
			{ key: '1', id: 'efectivo', name: 'Efectivo' },
			{ key: '2', id: 'tarjeta', name: 'Tarjeta' },
			{ key: '3', id: 'transferencia', name: 'Transferencia' },
			{ key: '4', id: 'qr', name: 'QR' },
		];

	function keytillApp() {
		return {
			// --- estado ---
			cmd: '',
			qtyPrefix: 0,
			lines: [],
			sel: 0,
			resIdx: 0,
			mode: 'sale', // sale | pay | customer | discount
			payStage: 'method', // method | amount | credit
			payMethod: null,
			padDigits: '',
			payError: '',

			// --- crédito en tienda (canje en el cobro) ---
			creditApplied: null, // { code, id, balance, apply } | null
			creditCodeDraft: '',
			creditError: '',
			// --- cliente (usuario de Woo reutilizable; anónimo = null) ---
			customer: null,       // { id, name, email, doc, iva } | null
			custStage: 'search',  // search | create | conflict
			custQuery: '',
			custResults: [],
			custSel: 0,
			custBusy: false,
			custError: '',
			custConflict: null,   // payload del cliente existente (dedup)
			newCust: { name: '', email: '', doc: '', phone: '', birthdate: '', iva: 'Consumidor Final' },
			// Debe coincidir con KEYTILL_Customers::fiscal_options() (default primero).
			fiscalOptions: [ 'Consumidor Final', 'Responsable Inscripto', 'Monotributista', 'Exento', 'No Responsable' ],
			discount: 0, // valor aplicado (porcentaje o monto fijo según discountType)
			discountType: 'percent', // 'percent' | 'fixed' — tipo aplicado
			discountDraft: '',
			discountTypeDraft: 'percent', // tipo en edición dentro del modal
			discLineIdx: null, // null = descuento global (F8); nº = índice de línea (por prenda)
			discStage: 'value', // paso del modal de descuento: 'scope' | 'line' | 'value'
			saleKey: null, // clave de idempotencia del intento de venta en curso
			statusText: 'Listo. Escaneá para empezar.',
			// Nivel del mensaje de estado: info (neutro/ok) | warn (ámbar) | error (rojo).
			// El setter de `status` lo resetea a 'info' en cada asignación; los casos
			// negativos suben el nivel en la línea siguiente. Así el color se auto-
			// resetea con la próxima acción normal sin tocar cada asignación.
			statusLevel: 'info',
			get status() { return this.statusText; },
			set status( v ) { this.statusText = v; this.statusLevel = 'info'; },
			toast: null,
			busy: false,
			lastOrder: null,
			flashId: null,

			// --- gift cards (venta como producto) ---
			giftPrompt: null,    // { product, qty } al pedir monto libre
			giftAmountDraft: '', // monto tipeado en el prompt
			saleResult: null,    // resumen de la venta cerrada (modal de confirmación + imprimir + gift cards)

			// --- arqueo de caja ---
			session: null,       // sesión de caja abierta (payload) | null
			sessionReady: false, // ya consultamos /cash/current al menos una vez
			openFloat: '',       // fondo inicial (apertura)
			countedCash: '',     // efectivo contado (cierre)
			withdrawAmount: '',  // monto del retiro
			withdrawReason: '',  // motivo del retiro
			withdrawAccount: 'efectivo', // 'efectivo' (física) | 'digital' — solo gestión elige digital
			cashError: '',       // error de operaciones de caja
			openCtx: '',         // contexto del modal de apertura: '' (inicio) | 'pay' | 'return'
			closeStage: 'form',  // form | done
			closeResult: null,   // payload del cierre para el resumen

			// --- devoluciones ---
			returnStage: 'lookup', // lookup | select | done
			returnNumber: '',      // número de venta a buscar
			returnData: null,      // orden encontrada (items con rqty)
			returnResult: null,    // payload del refund
			returnSel: 0,          // línea seleccionada
			returnError: '',
			returnMethod: 'credito', // 'credito' | 'efectivo' (medio de reintegro)

			// catálogo precargado (híbrido)
			catalog: [],
			byCode: {},
			methods: METHODS,

			// --- vendedores (etiqueta de quién atendió la venta) ---
			sellers: ( CONFIG.sellers || [] ),
			sellerId: '',

			// --- sucursales (stock por sucursal) ---
			branches: ( CONFIG.branches || [] ),
			openBranch: '', // sucursal elegida al abrir la caja
			stockFix: { open: false, items: [] }, // resolución de faltante al cobrar
			stockChoices: {},                      // product_id => { stock_branch } | { stock_force }

			// --- modo táctil (mobile / tablet) ---
			touch: false,       // pointer grueso: sin auto-foco, barra de acciones, keypad
			cartSheet: false,   // bottom sheet del carrito abierto (solo layouts apilados)

			// --- escaneo por cámara (ZXing, bajo demanda) ---
			scanOpen: false,
			scanBusy: false,
			scanError: '',
			scanStage: 'scanning',  // scanning | confirm | notfound | added
			scanMatch: null,        // producto pendiente de confirmar
			scanCode: '',           // último código leído (para "no encontrado")
			_reader: null,
			_zxingPromise: null,
			_lastCode: '',
			_lastCodeT: 0,

			// internos de foco
			_active: true,

			/* ---------------- ciclo de vida ---------------- */
			init: function () {
				var self = this;
				// Entorno táctil: pointer grueso (móvil/tablet). En este modo NO se
				// auto-enfoca (el teclado del sistema saltaría todo el tiempo); las
				// acciones van por botones. El detalle vive también en $store.pos.touch.
				this.touch = !! ( window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches );
				this.initSeller();
				this.initBranch();
				this.loadCatalog();
				this.loadSession();

				this._onKey = function ( e ) { self.handleKey( e ); };
				document.addEventListener( 'keydown', this._onKey, true );

				// Contrato de foco (solo desktop): re-foco por eventos + intervalo como
				// red de seguridad. En táctil se omite para no forzar el teclado en pantalla.
				if ( ! this.touch ) {
					this._focusGuard = function () {
						if ( self.$store.pos.view !== 'venta' ) { return; }
						if ( ! self._active || ! document.hasFocus() ) { return; }
						var a = document.activeElement;
						if ( ! a || a === document.body || a === document.documentElement ) { self.refocus(); }
					};
					this._onFocusOut = function () { setTimeout( self._focusGuard, 0 ); };
					document.addEventListener( 'focusout', this._onFocusOut );
					this._onMouseUp = function () { setTimeout( function () { self.refocus(); }, 0 ); };
					document.addEventListener( 'mouseup', this._onMouseUp );
					this._guardId = setInterval( this._focusGuard, 150 ); // red de seguridad
				}

				this._onBlur = function () { self._active = false; };
				window.addEventListener( 'blur', this._onBlur );
				this._onFocus = function () {
					self._active = true;
					self.refocus();
					self.loadCatalog(); // re-sync al volver a la caja (stock fresco)
				};
				window.addEventListener( 'focus', this._onFocus );

				// Cambio de pestaña del shell: al entrar a Venta retoma foco/teclado;
				// al salir cede el foco y cierra cualquier overlay abierto.
				this._onView = function ( e ) {
					if ( e.detail === 'venta' ) {
						self._active = true;
						self.refocus();
						self.loadCatalog();
					} else {
						self._active = false;
						if ( self.mode !== 'sale' ) { self.mode = 'sale'; }
					}
				};
				window.addEventListener( 'keytill:view', this._onView );

				// Un producto se editó en la consola (precio, stock, sucursal, variaciones,
				// alta/baja): invalida el throttle del catálogo para que la vista de venta
				// vuelva a consultarlo (y refresca ya si estamos en Venta).
				this._onCatalogDirty = function () {
					self._lastCatalog = 0;
					if ( self.$store.pos.view === 'venta' ) { self.loadCatalog( true ); }
				};
				window.addEventListener( 'keytill:catalog-dirty', this._onCatalogDirty );

				// Devolución pedida desde la pestaña Pedidos: reusa el flujo F7.
				this._onReturnReq = function ( e ) {
					var num = e && e.detail && e.detail.number;
					if ( ! num ) { return; }
					// Si no hay caja abierta, openReturn muestra el modal de apertura y
					// devuelve false: no seguimos con la búsqueda de la venta.
					if ( ! self.openReturn() ) { return; }
					self.returnNumber = String( num );
					self.lookupOrder();
				};
				window.addEventListener( 'keytill:return', this._onReturnReq );

				this.refocus();
			},

			/* ---------------- catálogo ---------------- */
			// Precarga el catálogo. Sin `force`, se throttlea (no recarga si se pidió
			// hace < 30 s): las recargas por re-foco de la pestaña pasan a ser baratas.
			// El server además cachea la respuesta en un transient. `force=true` para
			// pedir stock fresco tras vender / devolver.
			loadCatalog: function ( force ) {
				var now = Date.now();
				if ( ! force && this._lastCatalog && ( now - this._lastCatalog ) < 30000 ) { return; }
				var self = this;
				fetch( API.root + 'keytill/v1/products?all=1', {
					headers: { 'X-WP-Nonce': API.nonce },
					credentials: 'same-origin',
				} )
					.then( function ( r ) { return r.ok ? r.json() : []; } )
					.then( function ( list ) {
						self.catalog = Array.isArray( list ) ? list : [];
						var map = {};
						self.catalog.forEach( function ( p ) {
							if ( p.sku ) { map[ String( p.sku ).toUpperCase() ] = p; }
							// Código de barras: escaneable igual que el SKU.
							if ( p.barcode ) { map[ String( p.barcode ).toUpperCase() ] = p; }
						} );
						self.byCode = map;
						self._lastCatalog = Date.now();
					} )
					.catch( function () { /* sin catálogo: la búsqueda queda vacía */ } );
			},

			/* ---------------- arqueo de caja ---------------- */
			loadSession: function () {
				var self = this;
				return fetch( API.root + 'keytill/v1/cash/current', {
					headers: { 'X-WP-Nonce': API.nonce },
					credentials: 'same-origin',
				} )
					.then( function ( r ) { return r.ok ? r.json() : { session: null }; } )
					.then( function ( res ) {
						self.session = ( res && res.session ) ? res.session : null;
						self.sessionReady = true;
						if ( ! self.session && self.mode === 'sale' ) {
							self.mode = 'open'; // sin caja abierta: forzar apertura
						} else if ( self.session && self.mode === 'open' ) {
							self.mode = 'sale';
						}
						self.refocus();
					} )
					.catch( function () { self.sessionReady = true; } );
			},

			openCash: function () {
				if ( this.busy ) { return; }
				var f = parseFloat( ( this.openFloat || '0' ).toString().replace( ',', '.' ) ) || 0;
				if ( f < 0 ) { this.cashError = 'Fondo inicial inválido'; return; }
				// Con sucursales, hay que elegir la de la caja (de dónde sale/entra stock).
				if ( this.branches.length && ! this.openBranch ) { this.cashError = 'Elegí la sucursal de la caja'; return; }
				var self = this;
				this.busy = true;
				this.cashError = '';
				this.cashPost( 'cash/open', { opening_float: f, branch_id: this.openBranch }, function ( d ) {
					var ctx = self.openCtx; // por qué se pidió abrir (cobro/devolución)
					try { window.localStorage.setItem( 'keytill_branch', self.openBranch || '' ); } catch ( e ) {}
					self.session = d;
					self.mode = 'sale';
					self.openFloat = '';
					self.openCtx = '';
					self.status = 'Caja abierta · fondo ' + self.money( d.opening_float );
					// Retoma la acción que disparó la apertura: el carrito sigue intacto.
					if ( ctx === 'pay' ) { self.openPay(); }
					else if ( ctx === 'return' ) { self.openReturn(); }
					// Apertura proactiva (no para cobrar/devolver): si es encargado/admin
					// y la caja digital está cerrada, le ofrece abrirla (el cajero no la ve).
					else { self.maybeOfferDigital(); }
				} );
			},

			// Al abrir la caja física, recuerda al encargado/admin abrir la digital si
			// está cerrada. El cajero común no ve ni toca la caja digital.
			maybeOfferDigital: function () {
				if ( ! this.$store.pos.canManage ) { return; }
				var self = this;
				fetch( API.root + 'keytill/v1/digital/current?_=' + Date.now(), { headers: { 'X-WP-Nonce': API.nonce }, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( d ) {
						var dig = d && d.digital;
						if ( ! dig || dig.open ) { return; } // ya abierta o sin datos
						// Reapertura: abre con el saldo del último cierre (no pide monto).
						var msg = dig.seeded
							? 'La caja digital está cerrada. ¿Abrirla ahora?'
							: 'La caja digital (cuenta bancaria) nunca se abrió. ¿Abrirla ahora? Vas a cargar el saldo inicial.';
						if ( ! window.confirm( msg ) ) { return; }
						var body = {};
						if ( ! dig.seeded ) {
							var v = window.prompt( 'Saldo inicial de la caja digital (solo la primera vez):', '0' );
							if ( v === null ) { return; } // canceló
							body.initial = parseFloat( ( v || '0' ).toString().replace( ',', '.' ) ) || 0;
						}
						self.busy = true;
						self.cashPost( 'digital/open', body, function () { self.status = 'Caja digital abierta'; self.statusLevel = ''; } );
					} ).catch( function () {} );
			},

			// Cierra el modal de apertura sin abrir la caja: se puede seguir usando el
			// resto del POS (buscar productos, ver pedidos, armar el carrito). Cobrar y
			// devolver siguen exigiendo caja: vuelven a pedir la apertura en ese momento.
			dismissOpenCash: function () {
				this.mode = 'sale';
				this.cashError = '';
				this.openFloat = '';
				this.openCtx = '';
				this.refocus();
			},

			// Barrera de caja para operaciones que mueven plata (cobro/devolución).
			// Si no hay caja abierta, muestra el modal de apertura con el contexto para
			// que el usuario sepa por qué se lo pide. Devuelve true si hay caja (puede
			// seguir), false si la frenó.
			requireCash: function ( ctx ) {
				if ( this.session ) { return true; }
				this.openCtx = ctx || '';
				this.mode = 'open';
				this.cashError = '';
				this.refocus();
				return false;
			},

			openWithdraw: function () {
				if ( ! this.session ) { return; }
				this.mode = 'withdraw';
				this.withdrawAmount = '';
				this.withdrawReason = '';
				this.withdrawAccount = 'efectivo';
				this.cashError = '';
				this.refocus();
			},

			// Origen del retiro (solo gestión puede elegir la caja digital).
			setWithdrawAccount: function ( a ) {
				if ( a === 'digital' && ! this.$store.pos.canManage ) { return; }
				this.withdrawAccount = ( a === 'digital' ) ? 'digital' : 'efectivo';
			},

			confirmWithdraw: function () {
				if ( this.busy ) { return; }
				var a = parseFloat( ( this.withdrawAmount || '0' ).toString().replace( ',', '.' ) ) || 0;
				if ( a <= 0 ) { this.cashError = 'Monto inválido'; return; }
				var self = this;
				this.busy = true;
				this.cashError = '';
				// Retiro de la caja digital (cuenta bancaria): solo gestión.
				if ( this.withdrawAccount === 'digital' && this.$store.pos.canManage ) {
					this.cashPost( 'cash/move', { account: 'digital', kind: 'retiro', amount: a, concept: this.withdrawReason }, function () {
						self.mode = 'sale';
						self.status = 'Retiro digital ' + self.money( a );
					} );
					return;
				}
				// Retiro de la caja física (efectivo del cajón).
				this.cashPost( 'cash/withdraw', { amount: a, reason: this.withdrawReason }, function ( d ) {
					self.session = d;
					self.mode = 'sale';
					self.status = 'Retiro ' + self.money( a );
				} );
			},

			openClose: function () {
				if ( ! this.session ) { return; }
				// Cerrar/arquear = función de control: solo encargado/admin (el server
				// también lo valida). Un cajero no cierra su propio cajón.
				if ( this.$store && this.$store.pos && ! this.$store.pos.canManage ) {
					this.status = 'Solo el encargado puede cerrar la caja';
				this.statusLevel = 'warn';
					return;
				}
				if ( this.lines.length ) {
					this.status = 'Terminá o cancelá la venta antes de cerrar la caja';
				this.statusLevel = 'warn';
					return;
				}
				var self = this;
				// Refresca el esperado (pudo haber ventas desde la última carga).
				this.loadSession().then( function () {
					self.mode = 'close';
					self.closeStage = 'form';
					self.countedCash = '';
					self.closeResult = null;
					self.cashError = '';
					self.refocus();
				} );
			},

			confirmClose: function () {
				if ( this.busy ) { return; }
				var c = parseFloat( ( this.countedCash || '0' ).toString().replace( ',', '.' ) ) || 0;
				var self = this;
				this.busy = true;
				this.cashError = '';
				this.cashPost( 'cash/close', { counted_cash: c }, function ( d ) {
					self.closeResult = d;
					self.closeStage = 'done';
				} );
			},

			finishClose: function () {
				this.session = null;
				this.closeResult = null;
				this.closeStage = 'form';
				this.countedCash = '';
				this.mode = 'open';
				this.status = 'Caja cerrada. Abrí una nueva para vender.';
				this.refocus();
			},

			// Cancela un overlay de caja (retiro/cierre-form) y vuelve a vender.
			cancelCash: function () {
				this.mode = 'sale';
				this.cashError = '';
				this.countedCash = '';
				this.withdrawAmount = '';
				this.withdrawReason = '';
				this.refocus();
			},

			// Diferencia en vivo del cierre (contado − esperado).
			diffPreview: function () {
				if ( ! this.session ) { return 0; }
				var c = parseFloat( ( this.countedCash || '0' ).toString().replace( ',', '.' ) ) || 0;
				return Math.round( ( c - this.session.expected_cash ) * 100 ) / 100;
			},
			diffLabel: function ( d ) {
				return d < 0 ? 'FALTANTE' : ( d > 0 ? 'SOBRANTE' : 'EXACTO' );
			},
			diffClass: function ( d ) {
				return d < 0 ? 'is-neg' : ( d > 0 ? 'is-pos' : 'is-ok' );
			},

			// Helper POST para operaciones de caja.
			cashPost: function ( path, body, onOk ) {
				var self = this;
				fetch( API.root + 'keytill/v1/' + path, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': API.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( body ),
				} )
					.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
					.then( function ( res ) {
						self.busy = false;
						if ( ! res.ok ) {
							self.cashError = ( res.data && res.data.message ) ? res.data.message : 'Operación de caja fallida';
							self.refocus();
							return;
						}
						onOk( res.data );
						self.refocus();
					} )
					.catch( function () {
						self.busy = false;
						self.cashError = 'Error de red';
						self.refocus();
					} );
			},

			/* ---------------- helpers ---------------- */
			money: function ( n ) {
				return '$ ' + new Intl.NumberFormat( 'es-AR', {
					minimumFractionDigits: 2,
					maximumFractionDigits: 2,
				} ).format( n || 0 );
			},

			// Stock disponible de un producto: número, o null = ilimitado
			// (no gestiona stock pero está disponible). 0 = sin stock.
			availableFor: function ( p ) {
				// Con backorders habilitados, Woo permite vender igual: ilimitado.
				if ( p.backorders_allowed ) { return null; }
				// Distribuido: el tope real es por sucursal y se resuelve al cobrar
				// (se puede vender igual con confirmación). No se limita al agregar.
				if ( p.branch_managed ) { return null; }
				if ( p.manage_stock && p.stock_qty !== null && p.stock_qty !== undefined ) {
					return p.stock_qty;
				}
				return p.in_stock ? null : 0;
			},

			/* ---------------- faltante de stock por sucursal ---------------- */
			// Abre el flujo para elegir de dónde descontar cada prenda faltante.
			openStockFix: function ( shortages ) {
				var self = this;
				this.stockFix = {
					open: true,
					items: ( shortages || [] ).map( function ( s ) {
						var avail = s.available || {};
						var opts  = [];
						// Otras sucursales con stock (la propia se ofrece como "vender igual").
						self.branches.forEach( function ( b ) {
							if ( b.id === s.branch ) { return; }
							var q = avail[ b.id ];
							if ( q && q > 0 ) { opts.push( { branch: b.id, name: b.name, qty: q } ); }
						} );
						return {
							product_id: s.product_id,
							name: s.name,
							need: s.need,
							branch_name: s.branch_name,
							have: s.have,
							options: opts,
							choice: null,      // { stock_branch } | { stock_force: true }
							choiceLabel: ''    // texto de la opción elegida (para resaltar)
						};
					} )
				};
				this.mode = 'stockfix';
				this.refocus();
			},
			// Elige el origen para una prenda faltante.
			pickStockChoice: function ( idx, choice, label ) {
				if ( this.stockFix.items[ idx ] ) {
					this.stockFix.items[ idx ].choice = choice;
					this.stockFix.items[ idx ].choiceLabel = label;
				}
			},
			stockChoiceIs: function ( idx, label ) {
				return this.stockFix.items[ idx ] && this.stockFix.items[ idx ].choiceLabel === label;
			},
			stockFixReady: function () {
				return this.stockFix.items.length > 0 && this.stockFix.items.every( function ( it ) { return !! it.choice; } );
			},
			confirmStockFix: function () {
				if ( ! this.stockFixReady() ) { this.status = 'Elegí de dónde descontar cada prenda'; this.statusLevel = 'warn'; return; }
				var self = this;
				this.stockChoices = this.stockChoices || {};
				this.stockFix.items.forEach( function ( it ) { self.stockChoices[ it.product_id ] = it.choice; } );
				this.stockFix.open = false;
				this.mode = 'pay';       // vuelve al cobro
				this.confirmSale();      // reintenta con las elecciones
			},
			cancelStockFix: function () {
				this.stockFix.open = false;
				this.mode = 'pay';
				this.refocus();
			},

			// Stock en la sucursal de la caja para un producto distribuido (o null).
			branchStockHere: function ( p ) {
				if ( ! p || ! p.branch_managed ) { return null; }
				var bid = ( this.session && this.session.branch_id ) ? this.session.branch_id : this.openBranch;
				if ( ! bid || ! p.branch_stock ) { return null; }
				var v = p.branch_stock[ bid ];
				return ( v === undefined || v === null ) ? 0 : parseInt( v, 10 );
			},

			// Desglose de stock por sucursal para un producto distribuido, como lista
			// de segmentos [{txt, cls}] para pintar en una linea a primera vista:
			// "Centro: 3 / Shopping: 5" (tu sucursal resaltada), o sin stock propio
			// "Centro: sin stock / Shopping: 3". null si no aplica.
			branchAvail: function ( p ) {
				if ( ! p || ! p.branch_managed || ! this.branches.length ) { return null; }
				var map    = p.branch_stock || {};
				var hereId = ( this.session && this.session.branch_id ) ? this.session.branch_id : this.openBranch;
				var hereQty = null, hereName = '', others = [];
				this.branches.forEach( function ( b ) {
					var v = map[ b.id ];
					v = ( v === undefined || v === null ) ? 0 : parseInt( v, 10 );
					if ( isNaN( v ) ) { v = 0; }
					if ( hereId && String( b.id ) === String( hereId ) ) { hereQty = v; hereName = b.name; }
					else if ( v > 0 ) { others.push( { name: b.name, qty: v } ); }
				} );
				var parts = [];
				if ( hereQty !== null ) {
					parts.push( hereQty > 0
						? { txt: hereName + ': ' + hereQty, cls: 'bl-here' }
						: { txt: hereName + ': sin stock', cls: 'bl-out' } );
				}
				others.forEach( function ( o ) { parts.push( { txt: o.name + ': ' + o.qty, cls: 'bl-other' } ); } );
				if ( ! parts.length ) { parts.push( { txt: 'Sin stock', cls: 'bl-out' } ); }
				return parts;
			},

			// Badge de stock para un producto del catálogo (resultados).
			stockBadge: function ( p ) {
				// Distribuido: el desglose por sucursal va en la linea de abajo
				// (branchAvail); no se pinta badge aca para no duplicar.
				if ( p.branch_managed ) { return null; }
				var a = this.availableFor( p );
				if ( a === 0 ) { return { cls: 'is-out', label: 'sin stock' }; }
				if ( a !== null && a <= 3 ) { return { cls: 'is-low', label: 'stock ' + a }; }
				return null;
			},

			// Badge de stock para una línea del carrito (usa el stock capturado al agregar).
			lineBadge: function ( l ) {
				var a = l.avail;
				if ( a === 0 ) { return { cls: 'is-out', label: 'sin stock' }; }
				if ( a !== null && a !== undefined && l.qty >= a ) { return { cls: 'is-out', label: 'máx ' + a }; }
				if ( a !== null && a !== undefined && a <= 3 ) { return { cls: 'is-low', label: 'stock ' + a }; }
				return null;
			},

			// Recalcula y cachea el badge de una línea (se referencia como l.badge
			// en el template, evitando recomputarlo en cada render).
			refreshLineBadge: function ( i ) {
				if ( this.lines[ i ] ) { this.lines[ i ].badge = this.lineBadge( this.lines[ i ] ); }
			},

			search: function ( q ) {
				var n = norm( q );
				if ( ! n ) { return []; }
				// Multi-término: cada palabra de la consulta debe coincidir con el
				// arranque de alguna palabra del producto (nombre + variación +
				// categoría + SKU). Así "remera m rojo" trae la variación que sea
				// talle M y color rojo, en cualquier orden. Match por inicio de
				// palabra (no substring) para que "m" no pegue dentro de "demo".
				var terms = n.split( /\s+/ ).filter( Boolean );
				var toks = function ( s ) { return norm( s ).split( /[^a-z0-9]+/ ).filter( Boolean ); };
				return this.catalog.filter( function ( p ) {
					var tokens = toks( p.name ).concat( toks( p.variation ), toks( p.category ), toks( p.sku ), toks( p.barcode ) );
					return terms.every( function ( t ) {
						return tokens.some( function ( tok ) { return tok.indexOf( t ) === 0; } );
					} );
				} ).slice( 0, 6 );
			},

			refocus: function () {
				// En táctil no forzamos foco: el teclado del sistema aparece solo
				// cuando el usuario toca un campo a propósito.
				if ( this.touch ) { return; }
				// Solo la vista de venta mantiene el foco en el campo de comando.
				if ( this.$store && this.$store.pos && this.$store.pos.view !== 'venta' ) { return; }
				// No robar el foco de un <select> que el usuario está usando (ej. el de
				// vendedor en el cobro): si no, el desplegable se cierra solo.
				var aeSel = document.activeElement;
				if ( aeSel && 'SELECT' === aeSel.tagName ) { return; }
				// El alta de cliente tiene varios campos (nombre/email/DNI/select): no
				// mover el foco entre ellos. Si el usuario ya está en un campo del modal,
				// dejarlo escribir; si no, más abajo se enfoca el campo por defecto.
				if ( 'customer' === this.mode && 'create' === this.custStage && aeSel &&
					( 'INPUT' === aeSel.tagName || 'TEXTAREA' === aeSel.tagName ) ) {
					return;
				}
				// Retiro: tiene monto + motivo. Si el usuario ya está en un campo del
				// modal (cualquier input que no sea el buscador de comandos), dejarlo
				// escribir; si no, abajo se enfoca el monto por defecto.
				if ( 'withdraw' === this.mode && aeSel && aeSel !== this.$refs.cmd &&
					( 'INPUT' === aeSel.tagName || 'TEXTAREA' === aeSel.tagName ) ) {
					return;
				}
				this._active = true;
				var self = this;
				var refByMode = {
					customer: this.custStage === 'create' ? 'custName' : ( this.custStage === 'search' ? 'custSearch' : null ),
					open: 'cashOpen',
					withdraw: 'cashWithdraw',
					close: 'cashClose',
					return: this.returnStage === 'lookup' ? 'returnLookup' : null,
					pay: this.payStage === 'credit' ? 'payCredit' : null,
					giftcard: 'giftAmount',
				};
				var grab = function () {
					var name = refByMode[ self.mode ];
					var el = ( name && self.$refs[ name ] ) ? self.$refs[ name ] : self.$refs.cmd;
					if ( el && el.isConnected && document.activeElement !== el ) {
						el.focus( { preventScroll: true } );
					}
				};
				grab();
				requestAnimationFrame( grab );
				setTimeout( grab, 0 );
				setTimeout( grab, 60 );
			},

			toastNow: function ( kicker, title, detail, kind ) {
				var self = this;
				clearTimeout( this._toastId );
				this.toast = { kicker: kicker, title: title, detail: detail, kind: kind || 'ok' };
				this._toastId = setTimeout( function () { self.toast = null; }, 1800 );
			},

			/* ---------------- devoluciones / cambios ---------------- */
			openReturn: function () {
				// Devolver mueve plata (reintegro o crédito): exige caja abierta (el
				// server también lo valida). Devuelve false si la frenó.
				if ( ! this.requireCash( 'return' ) ) { return false; }
				this.mode = 'return';
				this.returnStage = 'lookup';
				this.returnNumber = '';
				this.returnData = null;
				this.returnResult = null;
				this.returnSel = 0;
				this.returnError = '';
				this.refocus();
				return true;
			},

			lookupOrder: function () {
				if ( this.busy ) { return; }
				var n = parseInt( this.returnNumber, 10 ) || 0;
				if ( ! n ) { this.returnError = 'Ingresá un número de venta'; return; }
				var self = this;
				this.busy = true;
				this.returnError = '';
				fetch( API.root + 'keytill/v1/orders/lookup?number=' + n, {
					headers: { 'X-WP-Nonce': API.nonce },
					credentials: 'same-origin',
				} )
					.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
					.then( function ( res ) {
						self.busy = false;
						if ( ! res.ok ) {
							self.returnError = ( res.data && res.data.message ) ? res.data.message : 'Venta no encontrada';
							self.refocus();
							return;
						}
						var d = res.data;
						// rqty = cantidad a devolver (arranca en 0; el cajero elige).
						d.items = d.items.map( function ( it ) { return Object.assign( {}, it, { rqty: 0 } ); } );
						self.returnData = d;
						self.returnSel = 0;
						// Medio de reintegro por defecto: efectivo si la venta fue en
						// efectivo del mostrador; crédito en tienda en el resto (tarjeta,
						// transferencia, QR o venta web).
						self.returnMethod = ( d.payment_method === 'efectivo' ) ? 'efectivo' : 'credito';
						self.returnStage = 'select';
						self.refocus();
					} )
					.catch( function () { self.busy = false; self.returnError = 'Error de red'; self.refocus(); } );
			},

			returnMove: function ( dir ) {
				if ( ! this.returnData || ! this.returnData.items.length ) { return; }
				var n = this.returnData.items.length;
				this.returnSel = ( this.returnSel + ( dir > 0 ? 1 : n - 1 ) ) % n;
			},

			returnAdjust: function ( delta ) {
				if ( ! this.returnData ) { return; }
				var it = this.returnData.items[ this.returnSel ];
				if ( ! it ) { return; }
				var q = it.rqty + delta;
				if ( q < 0 ) { q = 0; }
				if ( q > it.qty_refundable ) { q = it.qty_refundable; }
				it.rqty = q;
			},
			// Táctil: +/− por línea de devolución (selecciona la línea tocada).
			returnInc: function ( i ) { this.returnSel = i; this.returnAdjust( 1 ); },
			returnDec: function ( i ) { this.returnSel = i; this.returnAdjust( -1 ); },

			returnTotal: function () {
				if ( ! this.returnData ) { return 0; }
				return this.returnData.items.reduce( function ( a, it ) { return a + it.unit_price * it.rqty; }, 0 );
			},
			returnUnits: function () {
				if ( ! this.returnData ) { return 0; }
				return this.returnData.items.reduce( function ( a, it ) { return a + it.rqty; }, 0 );
			},
			setReturnMethod: function ( m ) {
				if ( m === 'credito' || m === 'efectivo' || m === 'transferencia' ) { this.returnMethod = m; }
			},

			confirmReturn: function () {
				if ( this.busy || ! this.returnData ) { return; }
				var lines = this.returnData.items
					.filter( function ( it ) { return it.rqty > 0; } )
					.map( function ( it ) { return { item_id: it.item_id, qty: it.rqty }; } );
				if ( ! lines.length ) { this.returnError = 'Elegí al menos una unidad a devolver (+)'; return; }
				var self = this;
				this.busy = true;
				this.returnError = '';
				fetch( API.root + 'keytill/v1/orders/refund', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': API.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { order_id: self.returnData.order_id, lines: lines, reason: '', refund_method: self.returnMethod } ),
				} )
					.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
					.then( function ( res ) {
						self.busy = false;
						if ( ! res.ok ) {
							self.returnError = ( res.data && res.data.message ) ? res.data.message : 'No se pudo procesar la devolución';
							self.refocus();
							return;
						}
						self.returnResult = res.data;
						self.returnStage = 'done';
						self.loadCatalog( true );  // stock reingresado
						self.loadSession();  // arqueo actualizado (devolución en efectivo)
						self.refocus();
					} )
					.catch( function () { self.busy = false; self.returnError = 'Error de red'; self.refocus(); } );
			},

			finishReturn: function () {
				this.mode = 'sale';
				this.returnData = null;
				this.returnResult = null;
				this.returnStage = 'lookup';
				this.returnNumber = '';
				this.status = 'Devolución registrada';
				this.refocus();
			},

			cancelReturn: function () {
				if ( this.returnStage === 'select' ) {
					this.returnStage = 'lookup';
					this.returnData = null;
					this.returnError = '';
					this.refocus();
					return;
				}
				this.mode = 'sale';
				this.returnData = null;
				this.returnStage = 'lookup';
				this.returnNumber = '';
				this.returnError = '';
				this.refocus();
			},

			/* ---------------- totales ---------------- */
			round2: function ( n ) {
				return Math.round( ( ( n || 0 ) + Number.EPSILON ) * 100 ) / 100;
			},
			subtotal: function () {
				return this.lines.reduce( function ( a, l ) { return a + l.price * l.qty; }, 0 );
			},
			// Subtotal descontable: excluye gift cards (son valor almacenado, no se
			// descuentan). Base sobre la que se calcula el factor del descuento.
			discountableSubtotal: function () {
				return this.lines.reduce( function ( a, l ) {
					return a + ( l.giftcard ? 0 : l.price * l.qty );
				}, 0 );
			},
			// Factor multiplicativo del descuento para un tipo/valor dados.
			factorFor: function ( type, value ) {
				if ( ! value ) { return 1; }
				if ( 'fixed' === type ) {
					var base = this.discountableSubtotal();
					return base > 0 ? Math.max( 0, ( base - value ) / base ) : 1;
				}
				return Math.max( 0, 1 - value / 100 );
			},
			// Factor del descuento POR LÍNEA (por prenda) de una línea del carrito.
			lineFactor: function ( l ) {
				if ( ! l || ! l.disc || l.giftcard ) { return 1; }
				if ( 'fixed' === l.discType ) {
					var base = l.price * l.qty;
					return base > 0 ? Math.max( 0, ( base - l.disc ) / base ) : 1;
				}
				return Math.max( 0, 1 - l.disc / 100 );
			},
			// Neto de una línea (con su descuento por prenda). Sin el global.
			lineNet: function ( l ) {
				return this.round2( l.price * l.qty * this.lineFactor( l ) );
			},
			// Total replicando el redondeo POR LÍNEA del server (fuente de verdad del
			// dinero): cada línea se redondea a 2 decimales y las gift cards van sin
			// descuento. Así la pantalla coincide con el total registrado en la orden.
			// Total combinando el descuento por línea (por prenda) con un factor global.
			// Son excluyentes en la práctica (aplicar uno limpia el otro), pero se
			// multiplican por robustez.
			totalWithFactor: function ( factor ) {
				var self = this;
				return this.lines.reduce( function ( a, l ) {
					return a + ( l.giftcard
						? self.round2( l.price * l.qty )
						: self.round2( l.price * l.qty * self.lineFactor( l ) * factor ) );
				}, 0 );
			},
			discountFactor: function () {
				return this.factorFor( this.discountType, this.discount );
			},
			total: function () {
				return this.round2( this.totalWithFactor( this.discountFactor() ) );
			},
			discountAmount: function () {
				return this.round2( this.subtotal() - this.total() );
			},
			itemCount: function () {
				return this.lines.reduce( function ( a, l ) { return a + l.qty; }, 0 );
			},

			/* ---------------- resultados de búsqueda ---------------- */
			rawQuery: function () {
				return this.cmd.replace( /^\d{1,3}\*/, '' ).trim();
			},
			results: function () {
				var q = this.rawQuery();
				if ( ! q ) { return []; }
				var self = this;
				return this.search( q ).map( function ( p ) {
					return Object.assign( {}, p, { badge: self.stockBadge( p ), avail: self.branchAvail( p ) } );
				} );
			},

			/* ---------------- input ---------------- */
			onInput: function ( e ) {
				var v = e.target.value;
				var m = v.match( /^(\d{1,3})\*$/ );
				if ( m ) {
					this.qtyPrefix = parseInt( m[ 1 ], 10 );
					this.cmd = '';
					this.resIdx = 0;
					e.target.value = '';
					return;
				}
				this.cmd = v;
				this.resIdx = 0;
			},

			/* ---------------- carrito ---------------- */
			// Texto de disponibilidad por sucursal: "N unidades disponibles en
			// sucursal X" (o sin cantidad si el stock no se gestiona).
			branchLine: function ( p ) {
				// Distribuido: stock en la sucursal de la caja ("N en {sucursal}").
				if ( p && p.branch_managed ) {
					var bname = ( this.session && this.session.branch_name ) ? this.session.branch_name : '';
					var h = this.branchStockHere( p );
					if ( bname !== '' && h !== null ) {
						return h + ( h === 1 ? ' unidad' : ' unidades' ) + ' en ' + bname;
					}
					return '';
				}
				// Legacy: sucursal informativa única (comportamiento anterior).
				if ( ! p || ! p.branch ) { return ''; }
				var n = ( p.stock_qty === null || p.stock_qty === undefined ) ? null : parseInt( p.stock_qty, 10 );
				if ( n !== null && ! isNaN( n ) && n >= 0 ) {
					return n + ( n === 1 ? ' unidad disponible' : ' unidades disponibles' ) + ' en sucursal ' + p.branch;
				}
				return 'Disponible en sucursal ' + p.branch;
			},

			addProduct: function ( prod, qty ) {
				var q = qty || 1;
				// Gift card: pide el monto (denominaciones + tipeo) antes de agregar.
				if ( prod.giftcard ) { return this.openGiftPrompt( prod, q ); }
				// Sin precio cargado: WooCommerce no lo deja vender. Se avisa acá con el
				// nombre en vez de fallar recién al cobrar con un id.
				if ( ! prod.price || Number( prod.price ) <= 0 ) {
					this.status = prod.name + ': sin precio cargado. Cargalo en Productos antes de venderlo.';
					this.statusLevel = 'warn';
					this.cmd = '';
					this.qtyPrefix = 0;
					this.resIdx = 0;
					if ( this.$refs.cmd ) { this.$refs.cmd.value = ''; }
					this.refocus();
					return;
				}
				var i = this.lines.findIndex( function ( l ) { return l.id === prod.id; } );
				var already = i >= 0 ? this.lines[ i ].qty : 0;
				var avail = this.availableFor( prod );

				// Guard suave anti-sobreventa (el server es el gate duro).
				var limited = false;
				if ( avail !== null ) {
					if ( already >= avail ) {
						this.status = avail > 0
							? ( 'Stock máximo alcanzado: ' + prod.name + ' (' + avail + ')' )
							: ( 'Sin stock: ' + prod.name );
						this.statusLevel = 'warn';
						if ( i >= 0 ) { // refresca el stock capturado en la línea existente
							this.lines[ i ].avail = avail;
							this.refreshLineBadge( i );
						}
						this.cmd = '';
						this.qtyPrefix = 0;
						this.resIdx = 0;
						if ( this.$refs.cmd ) { this.$refs.cmd.value = ''; }
						this.refocus();
						return;
					}
					if ( already + q > avail ) {
						q = avail - already;
						limited = true;
					}
				}

				if ( i >= 0 ) {
					this.lines[ i ].qty += q;
					this.lines[ i ].avail = avail;
					this.sel = i;
				} else {
					this.lines.push( {
						id: prod.id,
						code: prod.sku,
						name: prod.name,
						variation: prod.variation || '',
						branch: prod.branch || '',
						price: prod.price,
						qty: q,
						avail: avail,
						badge: null,
						giftcard: !! prod.giftcard,
						disc: 0,            // descuento por prenda (0 = sin descuento)
						discType: 'percent', // 'percent' | 'fixed'
					} );
					this.sel = this.lines.length - 1;
				}
				this.refreshLineBadge( this.sel );
				this.cmd = '';
				this.qtyPrefix = 0;
				this.resIdx = 0;
				var dispName = prod.name + ( prod.variation ? ' · ' + prod.variation : '' );
				this.status = limited
					? ( dispName + ': limitado al stock disponible (' + avail + ')' )
					: ( q + ' × ' + dispName );
				if ( limited ) { this.statusLevel = 'warn'; }
				if ( this.$refs.cmd ) { this.$refs.cmd.value = ''; }
				this.flashId = prod.id;
				var self = this;
				clearTimeout( this._flashT );
				this._flashT = setTimeout( function () { self.flashId = null; }, 260 );
				this.refocus();
			},

			/* ---------------- gift cards ---------------- */
			openGiftPrompt: function ( prod, qty ) {
				this.giftPrompt = {
					product: prod,
					qty: qty || 1,
					denoms: Array.isArray( prod.giftcard_denoms ) ? prod.giftcard_denoms : [],
				};
				this.giftAmountDraft = '';
				this.mode = 'giftcard';
				this.status = 'Monto de la gift card…';
				this.refocus();
			},
			pickGiftDenom: function ( amount ) {
				this.giftAmountDraft = String( amount );
				this.confirmGiftPrompt(); // elegir una denominación agrega directo
			},
			cancelGiftPrompt: function () {
				this.giftPrompt = null;
				this.giftAmountDraft = '';
				this.mode = 'sale';
				this.status = 'Listo.';
				this.refocus();
			},
			confirmGiftPrompt: function () {
				if ( ! this.giftPrompt ) { return; }
				var amt = parseFloat( String( this.giftAmountDraft ).replace( ',', '.' ) ) || 0;
				amt = Math.round( amt * 100 ) / 100;
				if ( amt <= 0 ) { this.status = 'Monto inválido'; this.statusLevel = 'warn'; return; }
				// Tope de gift card (0 = sin tope). El server igual lo valida.
				var gcMax = Number( CONFIG.giftcardMax ) || 0;
				if ( gcMax > 0 && amt > gcMax ) { this.status = 'Máximo por gift card: ' + this.money( gcMax ); this.statusLevel = 'warn'; return; }
				var p = this.giftPrompt.product;
				this.lines.push( {
					id: p.id,
					code: p.sku,
					name: p.name,
					price: amt,
					qty: this.giftPrompt.qty,
					avail: null,
					badge: null,
					giftcard: true,
					amount: amt, // monto elegido por unidad (se manda al server)
				} );
				this.sel = this.lines.length - 1;
				this.giftPrompt = null;
				this.giftAmountDraft = '';
				this.mode = 'sale';
				this.status = 'Gift card ' + this.money( amt ) + ' agregada';
				this.refocus();
			},
			printSale: function () {
				if ( this.saleResult && this.saleResult.printUrl ) {
					window.open( this.saleResult.printUrl, '_blank', 'noopener' );
				}
			},
			// Ticket de cambio (sin precios). No cierra el modal: se puede imprimir
			// también el comprobante de venta.
			printGift: function () {
				if ( this.saleResult && this.saleResult.giftUrl ) {
					window.open( this.saleResult.giftUrl, '_blank', 'noopener' );
				}
			},
			finishSaleResult: function () {
				this.saleResult = null;
				this.mode = 'sale';
				this.status = 'Listo. Escaneá para empezar.';
				this.refocus();
			},

			/* ---------------- vendedores ---------------- */
			// Inicializa el vendedor elegido: el último usado (localStorage) si sigue
			// activo; si no, el primero de la lista.
			initSeller: function () {
				if ( ! this.sellers.length ) { this.sellerId = ''; return; }
				var last = '';
				try { last = window.localStorage.getItem( 'keytill_seller' ) || ''; } catch ( e ) {}
				var ok = this.sellers.some( function ( s ) { return s.id === last; } );
				this.sellerId = ok ? last : this.sellers[ 0 ].id;
			},
			// Persiste la elección para la próxima venta.
			onSellerChange: function () {
				try { window.localStorage.setItem( 'keytill_seller', this.sellerId || '' ); } catch ( e ) {}
			},

			/* ---------------- sucursal de la caja ---------------- */
			// Sucursal por defecto para abrir la caja: la última usada (localStorage)
			// si sigue activa; si no, la primera.
			initBranch: function () {
				if ( ! this.branches.length ) { this.openBranch = ''; return; }
				var last = '';
				try { last = window.localStorage.getItem( 'keytill_branch' ) || ''; } catch ( e ) {}
				var ok = this.branches.some( function ( b ) { return b.id === last; } );
				this.openBranch = ok ? last : this.branches[ 0 ].id;
			},

			/* ---------------- escaneo por cámara (ZXing) ---------------- */
			// Abre el modal y arranca la cámara. ZXing se carga bajo demanda (no
			// pesa en el arranque ni en escritorio, que usa lector físico).
			openScanner: function () {
				var self = this;
				this.scanError = '';
				this.scanStage = 'scanning';
				this.scanMatch = null;
				this.scanCode = '';
				this._lastCode = '';
				this._lastCodeT = 0;
				this.scanOpen = true;
				if ( ! window.isSecureContext ) {
					this.scanError = 'La cámara necesita una conexión segura (HTTPS).';
					return;
				}
				if ( ! ( navigator.mediaDevices && navigator.mediaDevices.getUserMedia ) ) {
					this.scanError = 'Este dispositivo/navegador no permite usar la cámara.';
					return;
				}
				this.scanBusy = true;
				this._loadZXing()
					.then( function () { return self.$nextTick( function () { self.startScan(); } ); } )
					.catch( function () { self.scanBusy = false; self.scanError = 'No se pudo cargar el lector de cámara.'; } );
			},

			// Inyecta el bundle de ZXing una sola vez (global window.ZXing).
			_loadZXing: function () {
				if ( window.ZXing ) { return Promise.resolve(); }
				if ( this._zxingPromise ) { return this._zxingPromise; }
				var url = CONFIG.zxingUrl;
				if ( ! url ) { return Promise.reject(); }
				this._zxingPromise = new Promise( function ( resolve, reject ) {
					var s = document.createElement( 'script' );
					s.src = url;
					s.onload = function () { resolve(); };
					s.onerror = function () { reject(); };
					document.head.appendChild( s );
				} );
				return this._zxingPromise;
			},

			startScan: function () {
				var self = this;
				var video = this.$refs.scanVideo;
				if ( ! video || ! window.ZXing ) { this.scanBusy = false; return; }
				try {
					var hints = new Map();
					hints.set( ZXing.DecodeHintType.POSSIBLE_FORMATS, [
						ZXing.BarcodeFormat.CODE_128,
						ZXing.BarcodeFormat.EAN_13,
						ZXing.BarcodeFormat.EAN_8,
						ZXing.BarcodeFormat.UPC_A
					] );
					this._reader = new ZXing.BrowserMultiFormatReader( hints, { delayBetweenScanAttempts: 150 } );
					// facingMode 'environment' = cámara trasera (la útil para escanear).
					this._reader.decodeFromConstraints(
						{ video: { facingMode: { ideal: 'environment' } } },
						video,
						function ( result, err ) {
							if ( result ) { self.onScanCode( result.getText() ); }
							// err de "no encontrado" en cada frame vacío: se ignora.
						}
					).then( function () { self.scanBusy = false; } )
						.catch( function ( e ) {
							self.scanBusy = false;
							self.scanError = ( e && e.name === 'NotAllowedError' )
								? 'Permiso de cámara denegado. Habilitalo en el navegador y reintentá.'
								: 'No se pudo abrir la cámara.';
						} );
				} catch ( e ) {
					this.scanBusy = false;
					this.scanError = 'No se pudo iniciar el lector.';
				}
			},

			// Código leído: NO se agrega solo. Pasa a "confirmar" mostrando el producto;
			// el alta al carrito recién ocurre si el usuario confirma. La cámara sigue
			// viva (no se destruye entre lecturas) pero la detección queda "gateada"
			// por scanStage: mientras no estemos en 'scanning', se ignoran los frames.
			// Un anti-rebote del mismo código evita re-disparar el ítem recién leído
			// que todavía esté en cuadro al volver a escanear.
			onScanCode: function ( code ) {
				if ( this.scanStage !== 'scanning' ) { return; }
				code = ( code || '' ).trim();
				if ( ! code ) { return; }
				var now = Date.now();
				if ( code === this._lastCode && now - this._lastCodeT < 1500 ) { return; }
				this._lastCode = code;
				this._lastCodeT = now;
				if ( navigator.vibrate ) { try { navigator.vibrate( 60 ); } catch ( e ) {} }
				this.scanCode = code;
				var prod = this.byCode[ code.toUpperCase() ];
				if ( prod ) {
					this.scanMatch = prod;
					this.scanStage = 'confirm';
				} else {
					this.scanMatch = null;
					this.scanStage = 'notfound';
				}
			},

			// Confirma el alta del producto escaneado y pasa al estado "agregado".
			confirmScan: function () {
				if ( ! this.scanMatch ) { return; }
				this.addProduct( this.scanMatch, 1 );
				this.scanStage = 'added';
			},

			// Vuelve a escanear. La cámara sigue viva: solo reabrimos la etapa (no se
			// re-adquiere el stream → sin pantalla negra). Si la cámara no está activa
			// (p. ej. se venía de un error), la arrancamos.
			scanAgain: function () {
				var self = this;
				this.scanMatch = null;
				this.scanCode = '';
				this.scanError = '';
				this.scanStage = 'scanning';
				if ( ! this._reader ) {
					this.scanBusy = true;
					this.$nextTick( function () { self.startScan(); } );
				}
			},

			// Texto legible del producto en confirmación (nombre + variación).
			scanMatchLabel: function () {
				if ( ! this.scanMatch ) { return ''; }
				return this.scanMatch.name + ( this.scanMatch.variation ? ' · ' + this.scanMatch.variation : '' );
			},

			_stopReader: function () {
				if ( this._reader ) {
					try { this._reader.reset(); } catch ( e ) {}
					this._reader = null;
				}
				// Libera explícitamente la cámara (por si algún track quedó vivo).
				var v = this.$refs.scanVideo;
				if ( v && v.srcObject ) {
					try { v.srcObject.getTracks().forEach( function ( t ) { t.stop(); } ); } catch ( e ) {}
					v.srcObject = null;
				}
			},

			closeScanner: function () {
				this._stopReader();
				this.scanOpen = false;
				this.scanBusy = false;
				this.scanStage = 'scanning';
				this.scanMatch = null;
				this.refocus();
			},

			submitCommand: function () {
				var raw = this.cmd.trim();
				if ( ! raw ) {
					if ( this.lines.length ) { this.openPay(); }
					return;
				}
				var m = raw.match( /^(\d{1,3})\*(.*)$/ );
				var qty = this.qtyPrefix || 1;
				var rest = raw;
				if ( m ) {
					qty = parseInt( m[ 1 ], 10 ) || 1;
					rest = m[ 2 ].trim();
					if ( ! rest ) {
						this.qtyPrefix = qty;
						this.cmd = '';
						if ( this.$refs.cmd ) { this.$refs.cmd.value = ''; }
						return;
					}
				}
				var byCode = this.byCode[ rest.toUpperCase() ];
				if ( byCode ) { return this.addProduct( byCode, qty ); }
				var res = this.search( rest );
				if ( res.length ) {
					return this.addProduct( res[ Math.min( this.resIdx, res.length - 1 ) ], qty );
				}
				this.status = 'Sin resultados para “' + rest + '”';
			},

			adjustQty: function ( delta ) {
				if ( ! this.lines.length ) { return; }
				var i = Math.min( this.sel, this.lines.length - 1 );
				var line = this.lines[ i ];
				var q = line.qty + delta;
				if ( q <= 0 ) {
					var removed = this.lines.splice( i, 1 )[ 0 ];
					this.sel = Math.max( 0, Math.min( i, this.lines.length - 1 ) );
					this.status = 'Quitado: ' + removed.name;
					return;
				}
				if ( delta > 0 && line.avail !== null && line.avail !== undefined && q > line.avail ) {
					this.status = 'Sin stock para más: ' + line.name + ' (máx ' + line.avail + ')';
				this.statusLevel = 'warn';
					return;
				}
				this.lines[ i ].qty = q;
				this.refreshLineBadge( i );
				this.status = this.lines[ i ].name + ' → ' + q + ' u.';
			},

			removeLine: function () {
				if ( ! this.lines.length ) { return; }
				var i = Math.min( this.sel, this.lines.length - 1 );
				var removed = this.lines.splice( i, 1 )[ 0 ];
				this.sel = Math.max( 0, Math.min( i, this.lines.length - 1 ) );
				this.status = 'Línea quitada: ' + removed.name;
			},

			/* ---------------- táctil: steppers por línea ---------------- */
			// Los botones +/−/✕ del carrito operan por índice (reusan la lógica de
			// teclado seleccionando la línea tocada).
			lineInc: function ( i ) { this.sel = i; this.adjustQty( 1 ); },
			lineDec: function ( i ) { this.sel = i; this.adjustQty( -1 ); },
			lineRemove: function ( i ) { this.sel = i; this.removeLine(); },

			/* ---------------- táctil: bottom sheet del carrito ---------------- */
			toggleCartSheet: function () { this.cartSheet = ! this.cartSheet; },
			closeCartSheet: function () { this.cartSheet = false; },

			/* ---------------- táctil: keypad de monto (cobro) ---------------- */
			// Comparten estado con el teclado físico (padDigits). Solo actúan en la
			// etapa de importe del cobro.
			padPush: function ( d ) {
				if ( this.mode !== 'pay' || this.payStage !== 'amount' ) { return; }
				this.padDigits = ( this.padDigits + String( d ) ).slice( 0, 9 );
			},
			padBack: function () { this.padDigits = this.padDigits.slice( 0, -1 ); },
			padClear: function () { this.padDigits = ''; },

			/* ---------------- cobro ---------------- */
			openPay: function () {
				if ( ! this.lines.length ) {
					this.status = 'Carrito vacío: nada que cobrar';
				this.statusLevel = 'warn';
					return;
				}
				// Cobrar mueve plata: exige caja abierta (el server también lo valida).
				if ( ! this.requireCash( 'pay' ) ) { return; }
				this.mode = 'pay';
				this.payMethod = null;
				this.padDigits = '';
				this.payError = '';
				this.creditApplied = null;
				this.creditCodeDraft = '';
				this.creditError = '';
				// Si hay vendedores, primer paso: elegir vendedor. Si no, directo al medio.
				if ( this.sellers.length ) {
					if ( ! this.sellerId ) { this.sellerId = this.sellers[ 0 ].id; }
					this.payStage = 'seller';
					this.status = 'Cobro: elegí vendedor';
				} else {
					this.payStage = 'method';
					this.status = 'Cobro: elegí medio de pago';
				}
			},

			/* ---------------- paso: vendedor ---------------- */
			// Índice del vendedor resaltado (el actual, o 0).
			sellerIndex: function () {
				var self = this;
				var i = this.sellers.findIndex( function ( s ) { return s.id === self.sellerId; } );
				return i < 0 ? 0 : i;
			},
			// Mueve el resaltado con flechas.
			moveSeller: function ( delta ) {
				if ( ! this.sellers.length ) { return; }
				var n = this.sellers.length;
				var i = ( this.sellerIndex() + delta + n ) % n;
				this.sellerId = this.sellers[ i ].id;
			},
			// Elige el vendedor y avanza al paso de medio de pago.
			pickSeller: function ( id ) {
				if ( id ) { this.sellerId = id; }
				this.onSellerChange();
				this.payStage = 'method';
				this.status = 'Cobro: elegí medio de pago';
				this.refocus();
			},

			pickMethod: function ( id ) {
				this.payMethod = id;
				this.payStage = 'amount';
				this.padDigits = '';
				this.payError = '';
			},

			isCash: function () { return this.payMethod === 'efectivo'; },

			/* ---------------- crédito en el cobro ---------------- */
			// Monto del crédito aplicado a esta venta (0 si no hay).
			appliedCredit: function () {
				return this.creditApplied ? this.creditApplied.apply : 0;
			},
			// Lo que queda por cobrar por un medio real (total − crédito).
			chargeTotal: function () {
				return Math.max( 0, Math.round( ( this.total() - this.appliedCredit() ) * 100 ) / 100 );
			},

			openCreditEntry: function () {
				this.payStage = 'credit';
				this.creditCodeDraft = this.creditApplied ? this.creditApplied.code : '';
				this.creditError = '';
				this.refocus();
			},
			cancelCreditEntry: function () {
				this.payStage = 'method';
				this.creditError = '';
				this.refocus();
			},
			clearCredit: function () {
				this.creditApplied = null;
				this.creditError = '';
				this.status = 'Crédito quitado del cobro';
			},
			validateCredit: function () {
				if ( this.busy ) { return; }
				var code = ( this.creditCodeDraft || '' ).trim().toUpperCase();
				if ( ! code ) { this.creditError = 'Ingresá un código'; return; }
				var self = this;
				this.busy = true;
				this.creditError = '';
				fetch( API.root + 'keytill/v1/credit/lookup?code=' + encodeURIComponent( code ), {
					headers: { 'X-WP-Nonce': API.nonce },
					credentials: 'same-origin',
				} )
					.then( function ( r ) { return r.json().then( function ( d ) { return { ok: r.ok, data: d }; } ); } )
					.then( function ( res ) {
						self.busy = false;
						if ( ! res.ok ) {
							self.creditError = ( res.data && res.data.message ) ? res.data.message : 'Crédito no encontrado';
							self.refocus();
							return;
						}
						var d = res.data;
						if ( ! d.redeemable ) {
							self.creditError = d.reason || 'El crédito no es válido';
							self.refocus();
							return;
						}
						var apply = Math.min( d.balance, self.total() );
						self.creditApplied = { code: d.code, id: d.id, balance: d.balance, apply: apply };
						self.creditError = '';
						if ( self.chargeTotal() <= 0 ) {
							// El crédito cubre todo: pasar a confirmar.
							self.payStage = 'amount';
							self.payMethod = null;
							self.padDigits = '';
						} else {
							// Falta el resto: elegir medio.
							self.payStage = 'method';
						}
						self.status = 'Crédito ' + d.code + ' · aplica ' + self.money( apply );
						self.refocus();
					} )
					.catch( function () { self.busy = false; self.creditError = 'Error de red'; self.refocus(); } );
			},

			recibido: function () {
				if ( this.padDigits ) { return parseInt( this.padDigits, 10 ); }
				return this.isCash() ? 0 : this.chargeTotal();
			},
			vuelto: function () {
				return this.isCash() ? ( this.recibido() - this.chargeTotal() ) : 0;
			},

			// Clave única por intento de venta (idempotencia). Se genera una vez y se
			// reusa mientras el intento no concrete, así un reintento tras un timeout
			// no crea una venta duplicada. Se limpia al concretar (resetSale).
			genSaleKey: function () {
				if ( window.crypto && crypto.randomUUID ) { return crypto.randomUUID(); }
				return 'k' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2 );
			},
			confirmSale: function () {
				if ( this.busy ) { return; }
				var self = this;
				if ( ! this.saleKey ) { this.saleKey = this.genSaleKey(); }
				var due = this.chargeTotal(); // resto a cobrar por un medio real
				var method = null;
				if ( due > 0 ) {
					method = METHODS.find( function ( m ) { return m.id === self.payMethod; } );
					if ( ! method ) { this.payError = 'Elegí un medio de pago para el resto'; return; }
				}
				var methodName = method ? method.name : ( this.creditApplied ? 'Crédito en tienda' : 'Bonificado' );

				var tendered = null;
				if ( method && method.id === 'efectivo' ) {
					tendered = this.padDigits ? parseInt( this.padDigits, 10 ) : due;
					if ( tendered < due ) {
						this.status = 'Efectivo insuficiente';
					this.statusLevel = 'warn';
						return;
					}
				}

				var choices = this.stockChoices || {};
				var body = {
					items: this.lines.map( function ( l ) {
						var o = { product_id: l.id, qty: l.qty };
						if ( l.giftcard && l.amount ) { o.amount = l.amount; } // gift card de monto libre
						if ( l.disc > 0 && ! l.giftcard ) { o.discount = { type: l.discType, value: l.disc }; } // descuento por prenda
						// Resolución de faltante (elegida por el cajero): origen y/o vender igual.
						var ch = choices[ l.id ];
						if ( ch ) {
							if ( ch.stock_branch ) { o.stock_branch = ch.stock_branch; }
							if ( ch.stock_force ) { o.stock_force = true; }
						}
						return o;
					} ),
					payment_method: method ? method.id : null,
					customer_id: this.customer ? this.customer.id : null,
					discount: this.discount > 0 ? { type: this.discountType, value: this.discount } : null,
					register_session_id: this.session ? this.session.register_session_id : null,
					idempotency_key: this.saleKey,
					seller_id: ( this.sellers.length && this.sellerId ) ? this.sellerId : null,
				};
				if ( this.creditApplied ) { body.credit_code = this.creditApplied.code; }
				if ( tendered !== null ) { body.amount_tendered = tendered; }

				this.busy = true;
				this.status = 'Registrando venta…';

				fetch( API.root + 'keytill/v1/orders', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': API.nonce,
					},
					credentials: 'same-origin',
					body: JSON.stringify( body ),
				} )
					.then( function ( r ) {
						return r.json().then( function ( data ) {
							return { ok: r.ok, data: data };
						} );
					} )
					.then( function ( res ) {
						self.busy = false;
						if ( ! res.ok ) {
							// Faltante de stock por sucursal: no es un error a secas; se
							// abre el flujo para que el cajero elija de dónde descontar.
							var errData = ( res.data && res.data.data ) ? res.data.data : {};
							if ( res.data && res.data.code === 'keytill_branch_stock' && errData.shortages && errData.shortages.length ) {
								self.openStockFix( errData.shortages );
								return;
							}
							var msg = ( res.data && res.data.message ) ? res.data.message : 'No se pudo registrar la venta';
							self.status = 'Error: ' + msg;
							self.statusLevel = 'error';
							self.payError = msg;          // visible dentro del modal de cobro
							self.toastNow( 'ERROR', 'Venta no registrada', msg, 'error' );
							self.loadCatalog( true );     // re-sync stock (por si fue rechazo por stock)
							self.refocus();
							return;
						}
						var d = res.data;
						var change = ( d.change !== null && d.change !== undefined ) ? d.change : 0;
						self.lastOrder = d.number;
						var creditNote = ( d.credit_used > 0 ) ? ' · crédito ' + self.money( d.credit_used ) : '';
						self.status = 'Venta ' + d.number + ' cerrada · ' + methodName + creditNote;
						self.resetSale();
						self.loadCatalog( true ); // stock fresco tras vender
						// Modal de confirmación post-venta: resumen + imprimir + gift cards.
						self.saleResult = {
							number: d.number,
							methodName: methodName,
							total: d.total,
							change: change > 0 ? change : 0,
							creditUsed: d.credit_used || 0,
							giftcards: d.giftcards || [],
							printUrl: d.print_url || null,
							giftUrl: d.gift_url || null,
						};
						self.mode = 'saleresult';
						self.refocus();
					} )
					.catch( function () {
						self.busy = false;
						self.status = 'Error de red al registrar la venta';
					self.statusLevel = 'error';
						self.payError = 'Sin conexión. Reintentá el cobro (Enter).';
						self.toastNow( 'ERROR', 'Sin conexión', 'Reintentá el cobro', 'error' );
						self.refocus();
					} );
			},

			resetSale: function () {
				this.mode = 'sale';
				this.payStage = 'method';
				this.payMethod = null;
				this.padDigits = '';
				this.payError = '';
				this.creditApplied = null;
				this.creditCodeDraft = '';
				this.creditError = '';
				this.lines = [];
				this.sel = 0;
				this.cmd = '';
				this.qtyPrefix = 0;
				this.discount = 0;
				this.discountType = 'percent';
				this.discLineIdx = null;
				this.customer = null;
				this.cartSheet = false;
				this.saleKey = null; // el intento concretó: la próxima venta usa clave nueva
				this.stockChoices = {}; // elecciones de origen: solo válidas para esta venta
				this.stockFix = { open: false, items: [] };
				if ( this.$refs.cmd ) { this.$refs.cmd.value = ''; }
			},

			/* ---------------- overlays ---------------- */
			closeOverlay: function () {
				this.mode = 'sale';
				this.payStage = 'method';
				this.payMethod = null;
				this.padDigits = '';
				this.payError = '';
				this.creditApplied = null;
				this.creditCodeDraft = '';
				this.creditError = '';
				this.discountDraft = '';
				this.status = 'Listo.';
				this.refocus();
			},

			/* ---------------- cliente ---------------- */
			openCustomer: function () {
				this.custStage = 'search';
				this.custQuery = '';
				this.custResults = [];
				this.custSel = 0;
				this.custError = '';
				this.custConflict = null;
				this.mode = 'customer';
				this.refocus();
			},
			searchCustomers: function () {
				var self = this;
				var q = this.custQuery.trim();
				if ( q.length < 1 ) { this.custResults = []; return; }
				this.custBusy = true;
				fetch( API.root + 'keytill/v1/customers?q=' + encodeURIComponent( q ), {
					headers: { 'X-WP-Nonce': API.nonce },
					credentials: 'same-origin',
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( list ) {
						self.custBusy = false;
						self.custResults = Array.isArray( list ) ? list : [];
						self.custSel = 0;
					} )
					.catch( function () { self.custBusy = false; self.custResults = []; } );
			},
			moveCust: function ( d ) {
				if ( ! this.custResults.length ) { return; }
				this.custSel = Math.max( 0, Math.min( this.custResults.length - 1, this.custSel + d ) );
			},
			confirmCustSearch: function () {
				if ( this.custResults.length ) {
					return this.selectCustomer( this.custResults[ this.custSel ] );
				}
				// Sin resultados: pasar a crear, prellenando lo que se tipeó.
				return this.startCreateCustomer();
			},
			selectCustomer: function ( c ) {
				this.customer = { id: c.id, name: c.name, email: c.email, doc: c.doc, iva: c.iva };
				this.status = 'Cliente: ' + ( c.name || c.doc || c.email );
				this.mode = 'sale';
				this.refocus();
			},
			detachCustomer: function () {
				this.customer = null;
				this.status = 'Consumidor final';
				this.mode = 'sale';
				this.refocus();
			},
			startCreateCustomer: function () {
				var q = this.custQuery.trim();
				var pre = { name: '', email: '', doc: '', phone: '', birthdate: '', iva: 'Consumidor Final' };
				if ( /@/.test( q ) ) { pre.email = q; }
				else if ( /^[0-9][0-9.\-\s]*$/.test( q ) ) { pre.doc = q; }
				else if ( q ) { pre.name = q; }
				this.newCust = pre;
				this.custError = '';
				this.custStage = 'create';
				// Enfoca Nombre explícitamente (el contrato de foco ya no roba el foco
				// entre los campos del alta, así que no se puede delegar en refocus()).
				var self = this;
				this.$nextTick( function () { if ( self.$refs.custName ) { self.$refs.custName.focus( { preventScroll: true } ); } } );
			},
			submitCreateCustomer: function () {
				var self = this;
				var d = this.newCust;
				var email = ( d.email || '' ).trim();
				var doc = ( d.doc || '' ).trim();
				if ( ! email && ! doc ) {
					this.custError = 'Cargá al menos email o DNI.';
					return;
				}
				this.custBusy = true;
				this.custError = '';
				fetch( API.root + 'keytill/v1/customers', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': API.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { name: d.name, email: email, doc: doc, phone: ( d.phone || '' ).trim(), birthdate: d.birthdate || '', iva: d.iva } ),
				} )
					.then( function ( r ) { return r.json().then( function ( data ) { return { status: r.status, data: data }; } ); } )
					.then( function ( res ) {
						self.custBusy = false;
						if ( res.status === 201 ) { return self.selectCustomer( res.data ); }
						// 409: ya existe → mostrar el cliente para usarlo.
						if ( res.status === 409 && res.data && res.data.data && res.data.data.customer ) {
							self.custConflict = res.data.data.customer;
							self.custError = res.data.message || 'Ya existe un cliente con esos datos.';
							self.custStage = 'conflict';
							self.refocus();
							return;
						}
						self.custError = ( res.data && res.data.message ) ? res.data.message : 'No se pudo guardar el cliente.';
					} )
					.catch( function () { self.custBusy = false; self.custError = 'Sin conexión. Reintentá.'; } );
			},
			useConflictCustomer: function () {
				if ( this.custConflict ) { return this.selectCustomer( this.custConflict ); }
			},
			backToCreate: function () {
				this.custError = '';
				this.custConflict = null;
				this.custStage = 'create';
				this.refocus();
			},
			backToSearch: function () {
				this.custError = '';
				this.custStage = 'search';
				this.refocus();
			},

			// F8 / botón "Descuento": abre el flujo guiado (paso 1: alcance).
			openDiscount: function () {
				if ( ! this.lines.length ) { this.status = 'Carrito vacío: nada que bonificar'; this.statusLevel = 'warn'; return; }
				this.discLineIdx = null;
				this.discountDraft = '';
				this.discStage = 'scope';
				this.mode = 'discount';
				this.refocus();
			},
			// Líneas que se pueden bonificar (no gift cards), con su índice real.
			discountableLines: function () {
				var out = [];
				this.lines.forEach( function ( l, i ) { if ( ! l.giftcard ) { out.push( { i: i, l: l } ); } } );
				return out;
			},
			// Paso 1 → elige el alcance del descuento.
			pickDiscScope: function ( scope ) {
				if ( 'line' === scope ) {
					if ( ! this.discountableLines().length ) { this.status = 'No hay prendas para bonificar'; this.statusLevel = 'warn'; return; }
					this.discStage = 'line';
					this.refocus();
					return;
				}
				// global
				this.discLineIdx = null;
				this.discountTypeDraft = this.discountType;
				this.discountDraft = String( this.discount || '' );
				this.discStage = 'value';
				this.refocus();
			},
			// Paso 2 → elige la prenda y pasa a tipear el valor.
			pickDiscLine: function ( i ) {
				var l = this.lines[ i ];
				if ( ! l || l.giftcard ) { return; }
				this.discLineIdx = i;
				this.discountTypeDraft = l.discType || 'percent';
				this.discountDraft = String( l.disc || '' );
				this.discStage = 'value';
				this.refocus();
			},
			// Atajo directo (botón "%" por línea en táctil, o Shift+F8): va al valor.
			openLineDiscount: function ( i ) {
				if ( i === null || i === undefined || ! this.lines[ i ] ) { return; }
				var l = this.lines[ i ];
				if ( l.giftcard ) { this.status = 'Las gift cards no se bonifican'; this.statusLevel = 'warn'; return; }
				this.discLineIdx = i;
				this.discountTypeDraft = l.discType || 'percent';
				this.discountDraft = String( l.disc || '' );
				this.discStage = 'value';
				this.mode = 'discount';
				this.refocus();
			},
			// A qué se aplica el descuento (título del modal): '' = toda la venta.
			discountTargetName: function () {
				if ( this.discLineIdx !== null && this.lines[ this.discLineIdx ] ) {
					var l = this.lines[ this.discLineIdx ];
					return l.name + ( l.variation ? ' · ' + l.variation : '' );
				}
				return '';
			},
			// Alterna entre porcentaje y monto fijo; limpia lo tipeado al cambiar de unidad.
			setDiscountType: function ( t ) {
				t = ( 'fixed' === t ) ? 'fixed' : 'percent';
				if ( t === this.discountTypeDraft ) { return; }
				this.discountTypeDraft = t;
				this.discountDraft = '';
				this.refocus();
			},
			// Base del monto fijo según el alcance: la prenda (price*qty) o el subtotal.
			discountBase: function () {
				if ( this.discLineIdx !== null && this.lines[ this.discLineIdx ] ) {
					var l = this.lines[ this.discLineIdx ];
					return l.price * l.qty;
				}
				return this.discountableSubtotal();
			},
			// Valor tipeado ya saneado según la unidad (% tope 100, $ tope de la base).
			discountDraftValue: function () {
				var v = parseInt( this.discountDraft || '0', 10 );
				if ( isNaN( v ) || v < 0 ) { v = 0; }
				if ( 'fixed' === this.discountTypeDraft ) { return Math.min( v, this.discountBase() ); }
				return Math.min( 100, v );
			},
			discountPreview: function () {
				var v = this.discountDraftValue();
				var base = this.discountBase();
				var f = ( 'fixed' === this.discountTypeDraft )
					? ( base > 0 ? Math.max( 0, ( base - v ) / base ) : 1 )
					: Math.max( 0, 1 - v / 100 );
				if ( this.discLineIdx !== null && this.lines[ this.discLineIdx ] ) {
					// Preview del nuevo NETO de la prenda.
					return this.money( this.round2( base * f ) );
				}
				// Global: total ignorando descuentos por prenda (se limpian al aplicar).
				var self = this;
				var t = this.lines.reduce( function ( a, l ) {
					return a + ( l.giftcard ? self.round2( l.price * l.qty ) : self.round2( l.price * l.qty * f ) );
				}, 0 );
				return this.money( this.round2( t ) );
			},
			// Keypad táctil del descuento (mismo estado que el teclado: discountDraft).
			discPush: function ( d ) {
				var max = ( 'fixed' === this.discountTypeDraft ) ? 9 : 3;
				var v = ( this.discountDraft + String( d ) ).slice( 0, max );
				if ( 'percent' === this.discountTypeDraft && parseInt( v, 10 ) > 100 ) { v = '100'; }
				this.discountDraft = v;
			},
			discBack: function () { this.discountDraft = this.discountDraft.slice( 0, -1 ); },
			// Aplica el descuento tipeado (compartido por teclado y botón táctil).
			applyDiscount: function () {
				var v = this.discountDraftValue();
				// --- descuento POR PRENDA ---
				if ( this.discLineIdx !== null && this.lines[ this.discLineIdx ] ) {
					var l = this.lines[ this.discLineIdx ];
					l.disc = v;
					l.discType = this.discountTypeDraft;
					var hadGlobal = this.discount > 0;
					this.discount = 0; // exclusividad: se quita el global
					this.mode = 'sale';
					this.discountDraft = '';
					this.discLineIdx = null;
					if ( ! v ) { this.status = l.name + ': sin descuento'; }
					else if ( 'fixed' === l.discType ) { this.status = 'Bonificación ' + this.money( v ) + ' · ' + l.name; }
					else { this.status = 'Bonificación ' + v + '% · ' + l.name; }
					if ( hadGlobal ) { this.status += ' (se quitó el descuento general)'; }
					this.refocus();
					return;
				}
				// --- descuento GLOBAL ---
				this.discount = v;
				this.discountType = this.discountTypeDraft;
				var hadLine = this.lines.some( function ( x ) { return x.disc > 0; } );
				this.lines.forEach( function ( x ) { x.disc = 0; x.discType = 'percent'; } ); // exclusividad
				this.mode = 'sale';
				this.discountDraft = '';
				this.discLineIdx = null;
				if ( ! v ) {
					this.status = 'Sin descuento';
				} else if ( 'fixed' === this.discountType ) {
					this.status = 'Descuento ' + this.money( v );
				} else {
					this.status = 'Descuento ' + v + '%';
				}
				if ( hadLine ) { this.status += ' (se quitaron los descuentos por prenda)'; }
				this.refocus();
			},

			/* ---------------- teclado global ---------------- */
			handleKey: function ( e ) {
				// Fuera de la vista de venta, el teclado no lo maneja el POS de venta.
				if ( this.$store && this.$store.pos && this.$store.pos.view !== 'venta' ) { return; }
				this._active = true;
				var k = e.key;

				// Modo táctil: las acciones van por botones. Si el foco está en un campo
				// editable (búsqueda visible, inputs de los sheets) dejamos escribir nativo
				// y no interceptamos. La excepción es el campo de escaneo HID latente
				// (inputmode="none", sin teclado en pantalla), que sí usa el buffer de
				// comando como lo hace el campo de comando del desktop.
				if ( this.touch ) {
					var ae = document.activeElement;
					if ( ae && ae !== this.$refs.scan &&
						( ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.tagName === 'SELECT' || ae.isContentEditable ) ) {
						return;
					}
				}

				// Si el foco está en un <select> (ej. el de vendedor en el cobro), dejamos
				// que el teclado lo maneje de forma nativa (flechas/tipeo/Enter/Escape) y
				// no interceptamos.
				var aeSel = document.activeElement;
				if ( aeSel && 'SELECT' === aeSel.tagName ) { return; }

				// Teclas reservadas por el navegador: no mapear.
				if ( k === 'F1' || k === 'F3' || k === 'F5' || k === 'F6' || k === 'F11' || k === 'F12' ) { return; }
				if ( e.ctrlKey || e.metaKey ) { return; }

				// --- apertura de caja ---
				// ENTER abre; ESC la deja para después (se puede navegar el resto del
				// POS; cobrar/devolver la vuelven a pedir).
				if ( this.mode === 'open' ) {
					if ( k === 'Enter' ) { e.preventDefault(); return this.openCash(); }
					if ( k === 'Escape' ) { e.preventDefault(); return this.dismissOpenCash(); }
					return; // los dígitos los escribe el input del fondo
				}

				// --- retiro de efectivo ---
				if ( this.mode === 'withdraw' ) {
					if ( k === 'Escape' ) { e.preventDefault(); return this.cancelCash(); }
					if ( k === 'Enter' ) { e.preventDefault(); return this.confirmWithdraw(); }
					return; // monto/motivo los escriben los inputs
				}

				// --- cierre de caja (arqueo) ---
				if ( this.mode === 'close' ) {
					if ( k === 'Escape' ) {
						e.preventDefault();
						return this.closeStage === 'done' ? this.finishClose() : this.cancelCash();
					}
					if ( k === 'Enter' ) {
						e.preventDefault();
						return this.closeStage === 'done' ? this.finishClose() : this.confirmClose();
					}
					return;
				}

				// --- devolución / cambio ---
				if ( this.mode === 'return' ) {
					if ( this.returnStage === 'lookup' ) {
						if ( k === 'Escape' ) { e.preventDefault(); return this.cancelReturn(); }
						if ( k === 'Enter' ) { e.preventDefault(); return this.lookupOrder(); }
						return; // el número lo escribe el input
					}
					if ( this.returnStage === 'select' ) {
						e.preventDefault();
						if ( k === 'Escape' ) { return this.cancelReturn(); }
						if ( k === 'Enter' ) { return this.confirmReturn(); }
						if ( k === 'ArrowDown' ) { return this.returnMove( 1 ); }
						if ( k === 'ArrowUp' ) { return this.returnMove( -1 ); }
						if ( k === '+' || k === '=' ) { return this.returnAdjust( 1 ); }
						if ( k === '-' ) { return this.returnAdjust( -1 ); }
						if ( k === 'c' || k === 'C' ) { return this.setReturnMethod( 'credito' ); }
						if ( k === 'e' || k === 'E' ) { return this.setReturnMethod( 'efectivo' ); }
						if ( k === 't' || k === 'T' ) { return this.setReturnMethod( 'transferencia' ); }
						return;
					}
					// stage done
					e.preventDefault();
					if ( k === 'Enter' || k === 'Escape' ) { return this.finishReturn(); }
					return;
				}

				// --- resolución de faltante de stock (elegir origen) ---
				if ( this.mode === 'stockfix' ) {
					e.preventDefault();
					if ( k === 'Escape' ) { return this.cancelStockFix(); }
					if ( k === 'Enter' ) { return this.confirmStockFix(); }
					return; // el resto se elige con los botones
				}

				// --- modo cobro ---
				if ( this.mode === 'pay' ) {
					// Sub-etapa: ingreso del código de crédito (el input tipea; solo
					// interceptamos Enter/Escape).
					if ( this.payStage === 'credit' ) {
						if ( k === 'Escape' ) { e.preventDefault(); return this.cancelCreditEntry(); }
						if ( k === 'Enter' ) { e.preventDefault(); return this.validateCredit(); }
						return;
					}
					e.preventDefault();
					// Paso: vendedor (1er paso si hay vendedores cargados).
					if ( this.payStage === 'seller' ) {
						if ( k === 'Escape' ) { return this.closeOverlay(); }
						if ( k === 'Enter' ) { return this.pickSeller( this.sellerId ); }
						if ( k === 'ArrowDown' ) { return this.moveSeller( 1 ); }
						if ( k === 'ArrowUp' ) { return this.moveSeller( -1 ); }
						if ( /^[1-9]$/.test( k ) ) {
							var si = parseInt( k, 10 ) - 1;
							if ( si < this.sellers.length ) { return this.pickSeller( this.sellers[ si ].id ); }
						}
						return;
					}
					if ( k === 'Escape' ) {
						if ( this.payStage === 'amount' ) {
							this.payStage = 'method';
							this.payMethod = null;
							this.padDigits = '';
						} else {
							this.closeOverlay();
						}
						return;
					}
					if ( this.payStage === 'method' ) {
						if ( k === 'c' || k === 'C' ) { return this.openCreditEntry(); }
						if ( ( k === 'Delete' || k === 'Backspace' ) && this.creditApplied ) { return this.clearCredit(); }
						var mm = METHODS.find( function ( x ) { return x.key === k; } );
						if ( mm ) { return this.pickMethod( mm.id ); }
						// Nada por cobrar (crédito cubre todo o bonificación 100%): Enter
						// confirma directo, sin obligar a elegir un medio de pago.
						if ( k === 'Enter' && this.chargeTotal() <= 0 ) { return this.confirmSale(); }
						if ( k === 'ArrowDown' || k === 'ArrowUp' ) {
							var self = this;
							var cur = METHODS.findIndex( function ( x ) { return x.id === self.payMethod; } );
							var next = ( cur + ( k === 'ArrowDown' ? 1 : METHODS.length - 1 ) + METHODS.length ) % METHODS.length;
							this.payMethod = METHODS[ next ].id;
							return;
						}
						if ( k === 'Enter' ) { return this.pickMethod( this.payMethod || 'efectivo' ); }
						return;
					}
					// payStage === 'amount'
					// Solo el efectivo tipea un importe (para el vuelto); en los demás
					// medios no hay monto que ingresar, así que las teclas numéricas
					// siguen sirviendo para cambiar de medio sin volver atrás.
					if ( this.isCash() ) {
						if ( /^[0-9]$/.test( k ) ) { this.padDigits = ( this.padDigits + k ).slice( 0, 9 ); return; }
						if ( k === 'Backspace' ) { this.padDigits = this.padDigits.slice( 0, -1 ); return; }
						if ( k === 'Delete' ) { this.padDigits = ''; return; }
					}
					if ( k === 'Enter' ) { return this.confirmSale(); }
					var m2 = METHODS.find( function ( x ) { return x.key === k; } );
					if ( m2 ) { return this.pickMethod( m2.id ); }
					return;
				}

				// --- gift card: prompt de monto libre (el input tipea) ---
				if ( this.mode === 'giftcard' ) {
					if ( k === 'Escape' ) { e.preventDefault(); return this.cancelGiftPrompt(); }
					if ( k === 'Enter' ) { e.preventDefault(); return this.confirmGiftPrompt(); }
					return;
				}

				// --- confirmación post-venta: imprimir venta (P) / ticket de cambio (C) o continuar (Enter/Esc) ---
				if ( this.mode === 'saleresult' ) {
					e.preventDefault();
					if ( k === 'p' || k === 'P' ) { return this.printSale(); }
					if ( k === 'c' || k === 'C' ) { return this.printGift(); }
					if ( k === 'Enter' || k === 'Escape' ) { return this.finishSaleResult(); }
					return;
				}

				// --- modo cliente (buscar / crear / conflicto) ---
				if ( this.mode === 'customer' ) {
					if ( this.custStage === 'search' ) {
						if ( k === 'Escape' ) { e.preventDefault(); return this.closeOverlay(); }
						if ( k === 'Tab' ) { e.preventDefault(); return this.startCreateCustomer(); }
						if ( k === 'ArrowDown' ) { e.preventDefault(); return this.moveCust( 1 ); }
						if ( k === 'ArrowUp' ) { e.preventDefault(); return this.moveCust( -1 ); }
						if ( k === 'Delete' && this.customer && ! this.custQuery ) { e.preventDefault(); return this.detachCustomer(); }
						if ( k === 'Enter' ) { e.preventDefault(); return this.confirmCustSearch(); }
						return; // el input de búsqueda tipea
					}
					if ( this.custStage === 'create' ) {
						if ( k === 'Escape' ) { e.preventDefault(); return this.backToSearch(); }
						if ( k === 'Enter' ) { e.preventDefault(); return this.submitCreateCustomer(); }
						return; // inputs y select tipean; TAB mueve campos (nativo)
					}
					// conflicto
					e.preventDefault();
					if ( k === 'Enter' ) { return this.useConflictCustomer(); }
					if ( k === 'Escape' ) { return this.backToCreate(); }
					return;
				}

				// --- modo descuento (flujo guiado) ---
				if ( this.mode === 'discount' ) {
					e.preventDefault();
					// Paso 1: alcance.
					if ( this.discStage === 'scope' ) {
						if ( k === 'Escape' ) { return this.closeOverlay(); }
						if ( k === '1' ) { return this.pickDiscScope( 'global' ); }
						if ( k === '2' ) { return this.pickDiscScope( 'line' ); }
						if ( k === 'Enter' ) { return this.pickDiscScope( 'global' ); }
						return;
					}
					// Paso 2: elegir prenda (número = posición en la lista).
					if ( this.discStage === 'line' ) {
						if ( k === 'Escape' ) { this.discStage = 'scope'; return; }
						if ( /^[1-9]$/.test( k ) ) {
							var rows = this.discountableLines();
							var row = rows[ parseInt( k, 10 ) - 1 ];
							if ( row ) { return this.pickDiscLine( row.i ); }
							return;
						}
						return;
					}
					// Paso 3: valor.
					if ( k === 'Escape' ) { return this.closeOverlay(); }
					if ( k === '%' ) { return this.setDiscountType( 'percent' ); }
					if ( k === '$' ) { return this.setDiscountType( 'fixed' ); }
					// Tab (o Shift+Tab) alterna entre las dos unidades: solo hay dos.
					if ( k === 'Tab' ) { return this.setDiscountType( this.discountTypeDraft === 'percent' ? 'fixed' : 'percent' ); }
					if ( /^[0-9]$/.test( k ) ) { return this.discPush( k ); }
					if ( k === 'Backspace' ) { return this.discBack(); }
					if ( k === 'Enter' ) { return this.applyDiscount(); }
					return;
				}

				// --- modo venta ---
				if ( k === 'F2' ) { e.preventDefault(); return this.openPay(); }
				if ( k === 'F4' ) { e.preventDefault(); return this.openCustomer(); }
				if ( k === 'F8' ) {
					e.preventDefault();
					// Shift+F8 = bonificar la prenda seleccionada; F8 = descuento general.
					return e.shiftKey ? this.openLineDiscount( this.sel ) : this.openDiscount();
				}
				if ( k === 'F7' ) { e.preventDefault(); return this.openReturn(); }
				if ( k === 'F9' ) { e.preventDefault(); return this.openClose(); }
				if ( k === 'F10' ) { e.preventDefault(); return this.openWithdraw(); }
				if ( k === 'Escape' ) {
					e.preventDefault();
					if ( this.cmd || this.qtyPrefix ) {
						this.cmd = '';
						this.qtyPrefix = 0;
						this.resIdx = 0;
						if ( this.$refs.cmd ) { this.$refs.cmd.value = ''; }
						this.status = 'Campo limpio';
						return;
					}
					if ( this.lines.length ) {
						this.lines = [];
						this.sel = 0;
						this.discount = 0;
						this.discountType = 'percent';
						this.discLineIdx = null;
						this.customer = null;
						this.stockChoices = {};
						this.status = 'Venta cancelada';
					}
					return;
				}
				if ( k === 'Enter' ) { e.preventDefault(); return this.submitCommand(); }
				if ( k === '+' || k === '=' ) { e.preventDefault(); return this.adjustQty( 1 ); }
				if ( k === '-' && ! this.cmd ) { e.preventDefault(); return this.adjustQty( -1 ); }
				if ( k === 'Delete' ) { e.preventDefault(); return this.removeLine(); }
				if ( k === 'ArrowDown' || k === 'ArrowUp' ) {
					e.preventDefault();
					var res = this.results();
					if ( res.length ) {
						this.resIdx = ( this.resIdx + ( k === 'ArrowDown' ? 1 : res.length - 1 ) ) % res.length;
						return;
					}
					if ( ! this.lines.length ) { return; }
					this.sel = ( this.sel + ( k === 'ArrowDown' ? 1 : this.lines.length - 1 ) ) % this.lines.length;
					return;
				}
			},
		};
	}

	// Registro en Alpine.
	document.addEventListener( 'alpine:init', function () {
		// Store compartido del shell: vista activa, permisos, API. Cada pestaña
		// (venta / pedidos / productos / clientes) lo lee para saber si está activa.
		window.Alpine.store( 'pos', {
			view: 'venta',
			canManage: ! ! CONFIG.canManage,
			touch: false, // pointer grueso (móvil/tablet): layout y comportamiento táctil
			api: API,
			tabs: ( function () {
				var t = [
					{ id: 'venta', label: 'Venta' },
					{ id: 'pedidos', label: 'Pedidos' },
					{ id: 'productos', label: 'Productos' },
					{ id: 'clientes', label: 'Clientes' }
				];
				// Caja/Banco: solo gestión (ve ambas cajas, abre/cierra la digital).
				if ( CONFIG.canManage ) { t.push( { id: 'cajabanco', label: 'Caja / Banco' } ); }
				return t;
			} )(),
			_valid: CONFIG.canManage
				? [ 'venta', 'pedidos', 'productos', 'clientes', 'cajabanco' ]
				: [ 'venta', 'pedidos', 'productos', 'clientes' ],
			init: function () {
				var self = this;
				// Detección de entorno táctil para todo el shell (layout de las pestañas
				// + barra de acciones). Añade .is-touch al root para el CSS de comportamiento.
				this.touch = !! ( window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches );
				if ( this.touch ) {
					var root = document.getElementById( 'keytill-app' );
					if ( root ) { root.classList.add( 'is-touch' ); }
				}
				var fromHash = ( location.hash || '' ).replace( '#', '' );
				if ( this._valid.indexOf( fromHash ) !== -1 ) { this.view = fromHash; }
				window.addEventListener( 'hashchange', function () {
					var v = ( location.hash || '' ).replace( '#', '' );
					if ( self._valid.indexOf( v ) !== -1 && v !== self.view ) { self._apply( v ); }
				} );
			},
			go: function ( v ) {
				if ( this._valid.indexOf( v ) === -1 || v === this.view ) { return; }
				this._apply( v );
				if ( location.hash !== '#' + v ) { location.hash = v; }
			},
			_apply: function ( v ) {
				this.view = v;
				// Avisar al componente de venta para que ceda o retome el foco/teclado.
				window.dispatchEvent( new CustomEvent( 'keytill:view', { detail: v } ) );
			}
		} );

		window.Alpine.data( 'keytillApp', keytillApp );
	} );
}() );
