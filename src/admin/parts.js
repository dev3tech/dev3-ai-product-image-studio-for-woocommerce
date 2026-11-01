import { __ } from '@wordpress/i18n';
import { Badge } from '../core3/ui';

export const STATUS = {
	pending: { tone: 'warning', label: __( 'Waiting for review', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	approved: { tone: 'success', label: __( 'Approved', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	rejected: { tone: 'neutral', label: __( 'Rejected', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	reverted: { tone: 'info', label: __( 'Reverted', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	failed: { tone: 'danger', label: __( 'Failed', 'dev3-ai-product-image-studio-for-woocommerce' ) },
};

export const MODES = [
	{ value: 'replace', label: __( 'Replace in product', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'add', label: __( 'Add to gallery', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'library', label: __( 'Keep in library only', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

export function StatusBadge( { status } ) {
	const s = STATUS[ status ] || { tone: 'neutral', label: status };
	return (
		<Badge tone={ s.tone }>
			{ s.label }
		</Badge>
	);
}

/**
 * Health badges for a library row.
 *
 * @param {Object} props       Props.
 * @param {Object} props.flags Flags.
 * @return {Element} Badges.
 */
export function ImageFlags( { flags } ) {
	if ( ! flags ) {
		return null;
	}
	const items = [];
	if ( flags.missing_alt ) {
		items.push( <Badge key="alt" tone="danger">{ __( 'No alt text', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Badge> );
	}
	if ( flags.low_res ) {
		items.push( <Badge key="res" tone="warning">{ __( 'Low-res', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Badge> );
	}
	if ( flags.not_white === true ) {
		items.push( <Badge key="white" tone="warning">{ __( 'Not on white', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Badge> );
	}
	if ( flags.not_square ) {
		items.push( <Badge key="sq" tone="neutral">{ __( 'Not square', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Badge> );
	}
	if ( ! items.length ) {
		items.push( <Badge key="ok" tone="success">{ __( 'Looks good', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Badge> );
	}
	return <span className="dev3is-flags">{ items }</span>;
}

export const formatBytes = ( n ) => {
	if ( ! n ) {
		return '—';
	}
	if ( n < 1024 * 1024 ) {
		return `${ Math.round( n / 1024 ) } KB`;
	}
	return `${ ( n / ( 1024 * 1024 ) ).toFixed( 1 ) } MB`;
};

/**
 * Small result card (dashboard).
 *
 * @param {Object} props        Props.
 * @param {Object} props.result Result.
 * @param {string} props.label  Operation label.
 * @return {Element} Card.
 */
export function ResultThumb( { result, label } ) {
	const img = result.after || result.before;
	const href = `#/studio/${ result.source_id }/${ result.product_id || 0 }/${ result.slot || 'media' }`;
	return (
		<a className="dev3is-rthumb" href={ href }>
			<span className={ `dev3is-rthumb__img${ result.kind === 'alt' ? ' is-alt' : '' }` }>
				{ img && <img src={ img.thumb } alt="" loading="lazy" /> }
				{ result.kind === 'alt' && <span className="dev3is-rthumb__alt">Alt</span> }
			</span>
			<span className="dev3is-rthumb__body">
				<span className="dev3is-rthumb__title">{ result.product_name || ( result.before && result.before.title ) }</span>
				<span className="dev3is-rthumb__meta">{ label }</span>
				<StatusBadge status={ result.status } />
			</span>
		</a>
	);
}
