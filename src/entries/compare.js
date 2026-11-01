import { initCompare } from '../frontend/compare';
import '../frontend/compare.css';

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => initCompare() );
} else {
	initCompare();
}
