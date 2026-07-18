/**
 * Accordion toggle for diagnostic guide panels.
 */
function cnscToggleGuide( btn ) {
	var guideId  = btn.getAttribute( 'aria-controls' );
	var guide    = document.getElementById( guideId );
	if ( ! guide ) { return; }
	var expanded = btn.getAttribute( 'aria-expanded' ) === 'true';
	if ( expanded ) {
		guide.style.display = 'none';
		btn.setAttribute( 'aria-expanded', 'false' );
		btn.classList.remove( 'wsc-guide-open' );
	} else {
		guide.style.display = 'block';
		btn.setAttribute( 'aria-expanded', 'true' );
		btn.classList.add( 'wsc-guide-open' );
	}
}
