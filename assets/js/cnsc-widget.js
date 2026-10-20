/**
 * AJAX refresh for the dashboard widget.
 * Dynamic data supplied by wp_localize_script() as cnscWidgetData.
 */
function cnscRefresh( btn ) {
	var widget = document.getElementById( 'wsc-widget' );
	if ( ! widget ) { return; }
	var originalHtml = btn.innerHTML;
	var fail = function () {
		btn.disabled  = false;
		btn.innerHTML = originalHtml;
		window.alert( cnscWidgetData.errorText );
	};
	btn.disabled    = true;
	btn.textContent = cnscWidgetData.refreshingText;
	var xhr = new XMLHttpRequest();
	xhr.open( 'POST', cnscWidgetData.ajaxUrl );
	xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
	xhr.onload = function () {
		// 権限・nonceエラー時は "-1" や "0" が返るため、診断結果のHTMLかを確認する。
		if ( 200 === xhr.status && -1 !== xhr.responseText.indexOf( 'wsc-widget' ) ) {
			widget.outerHTML = xhr.responseText;
		} else {
			fail();
		}
	};
	xhr.onerror = fail;
	xhr.send( 'action=cnsc_refresh&nonce=' + encodeURIComponent( btn.dataset.nonce ) );
}
