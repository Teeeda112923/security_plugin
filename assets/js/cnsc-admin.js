/**
 * AJAX refresh for the dedicated admin page.
 * Dynamic data supplied by wp_localize_script() as cnscAdminData.
 */
function cnscAdminRefresh( btn ) {
	var body = document.getElementById( 'wsc-admin-body' );
	if ( ! body ) { return; }
	btn.disabled    = true;
	btn.textContent = cnscAdminData.refreshingText;
	var xhr = new XMLHttpRequest();
	xhr.open( 'POST', cnscAdminData.ajaxUrl );
	xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
	xhr.onload = function () {
		if ( 200 === xhr.status ) { body.outerHTML = xhr.responseText; }
	};
	xhr.send( 'action=cnsc_admin_refresh&nonce=' + btn.dataset.nonce );
}
