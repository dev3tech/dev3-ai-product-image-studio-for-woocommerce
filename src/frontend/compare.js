/**
 * Front-end before/after slider: links the range input to the CSS position variable.
 * Without JS the comparison still shows at its initial position.
 *
 * @param {ParentNode} root Where to look for sliders.
 */
export function initCompare( root = document ) {
	root.querySelectorAll( '.dev3is-compare' ).forEach( ( fig ) => {
		if ( fig.dataset.dev3isReady ) {
			return;
		}
		fig.dataset.dev3isReady = '1';
		const range = fig.querySelector( '.dev3is-compare__range' );
		if ( ! range ) {
			return;
		}
		const update = () => fig.style.setProperty( '--dev3is-pos', `${ Math.min( 100, Math.max( 0, Number( range.value ) ) ) }%` );
		range.addEventListener( 'input', update );
		range.addEventListener( 'change', update );
		update();
	} );
}
