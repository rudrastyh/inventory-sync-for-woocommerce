jQuery( function( $ ) {

	$( '#rudr_is_standalone_store' ).change( function() {

		const addStoreFormWPMU = $(this).parent().parent().next()
		const addStoreForm = addStoreFormWPMU.next();

		if( $(this).is( ':checked' ) ) {
			addStoreForm.show()
			addStoreFormWPMU.hide()
		} else {
			addStoreFormWPMU.show()
			addStoreForm.hide()
		}

	} );

	// for regular websites
	$( '#do_new_website' ).click( function( e ) {

		e.preventDefault();

		const button = $(this);
		const url = $( '#new_site_url' );
		const login = $( '#new_site_username' );
		const pwd = $( '#new_site_pwd' );
		const noticeContainer = $( '#rudr-isfw-stores-notices' );
		const sitesContainer = $( '.rudr-isfw-stores-table' ).find( 'tbody' );

		if( '' === url.val() ) {
			url.focus();
			return;
		}

		if( '' === login.val() ) {
			login.focus();
			return;
		}

		if( '' === pwd.val() ) {
			pwd.focus();
			return;
		}

		$.ajax( {
			type : 'POST',
			url : ajaxurl,
			data : {
				url : url.val(),
				login : login.val(),
				pwd : pwd.val(),
				_ajax_nonce: isfw_settings.nonce,
				action : 'isfwaddstore'
			},
			beforeSend : function( xhr ){
				button.prop( 'disabled', true );
				noticeContainer.empty();
			},
			success : function( data ) {
				button.prop( 'disabled', false );
				if( true === data.success ) {
					noticeContainer.html( '<div class="notice notice-success"><p>' + data.data.message + '</p></div>' );
					if( sitesContainer.find( '.rudr-isfw-store' ).length > 0 ) {
						sitesContainer.append( data.data.tr );
					} else {
						sitesContainer.html( data.data.tr );
					}
					url.val( '' );
					login.val( '' );
					pwd.val( '' );
				} else {
					noticeContainer.html( '<div class="notice notice-error"><p>' + data.data[0].message + '</p></div>' );
				}
			}
		} );

		window.onbeforeunload = '';

	} );

	// for multisite
	$( '#do_new_multisite' ).click( function( e ) {

		e.preventDefault();

		const button = $(this);
		const blodId = $( 'select[name="new_blog_id"]' );
		const noticeContainer = $( '#rudr-isfw-stores-notices' );
		const sitesContainer = $( '.rudr-isfw-stores-table' ).find( 'tbody' );

		if( '' == blodId.val() ) {
			blodId.focus();
			return;
		}

		$.ajax( {
			type : 'POST',
			url : ajaxurl,
			data : {
				new_blog_id : blodId.val(),
				_ajax_nonce: isfw_settings.nonce,
				action : 'isfwaddstorewpmu'
			},
			beforeSend : function( xhr ){
				button.prop( 'disabled', true );
				noticeContainer.empty();
			},
			success : function( data ) {
				button.prop( 'disabled', false );
				if( true === data.success ) {
					noticeContainer.html( '<div class="notice notice-success"><p>' + data.data.message + '</p></div>' );
					if( sitesContainer.find( '.rudr-isfw-store' ).length > 0 ) {
						sitesContainer.append( data.data.tr );
					} else {
						sitesContainer.html( data.data.tr );
					}
					blodId.val( '' ).trigger( 'change' );
				} else {
					noticeContainer.html( '<div class="notice notice-error"><p>' + data.data[0].message + '</p></div>' );
				}
			}
		} );

		window.onbeforeunload = '';

	} );



	$( 'body' ).on( 'click', '.rudr-isfw-remove-store', function( e ) {

		e.preventDefault();
		if( true !== confirm( isfw_settings.deleteStoreConfirmText ) ) {
			return;
		}
		$(this).prop( 'disabled', true );

		const url = $(this).parent().parent().next().text();
		const tr = $(this).parent().parent().parent();
		const sitesContainer = $( '.rudr-isfw-stores-table' ).find( 'tbody' );

		$.ajax( {
			type : 'POST',
			url : ajaxurl,
			data : {
				url : url,
				_ajax_nonce: isfw_settings.nonce,
				action : 'isfwremovestore'
			},
			success : function( data ) {
				if( true === data.success ) {
					if( sitesContainer.find( '.rudr-isfw-store' ).length == 1 ) {
						sitesContainer.html( '<td colspan="2">' + data.data.message + '</td>' );
					} else {
						tr.remove();
					}
				}
			}
		} );

	} );

	// select2 for sites
	$( '.isfw-select-site' ).each( function() {
		var select2_args = {
			allowClear:  $( this ).data( 'allow_clear' ) ? true : false,
			placeholder: $( this ).data( 'placeholder' ),
			minimumInputLength: 2,
			escapeMarkup: function( m ) {
				return m;
			},
			ajax: {
				url: ajaxurl,
				dataType: 'json',
				delay: 500,
				data: function( params ) {
					return {
						q: params.term,
						action: 'isfwgetstoreswpmu',
						_ajax_nonce: isfw_settings.nonce,
						site__not_in: $( this ).data( 'site__not_in' )
					};
				},
				processResults: function (response, params) {
					var sites = [];
					console.log( response.data );
					if ( response.data ) {
						$.each( response.data, function( index, site ) {
							sites.push( { id: site.id, text: site.text } );
						});
					}
					return {
						results: sites
					}
				},
				cache: true
			}
		}

		$( this ).selectWoo( select2_args )
	})


} );
