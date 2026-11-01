import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiDraftReview, Badge, Button, Checkbox, Inline, Select, useToast } from '../core3/ui';
import CompareSlider from './CompareSlider';
import { opLabel } from './ops';
import { MODES, StatusBadge, formatBytes } from './parts';

export const ALT_FIELDS = [
	{ key: 'alt', label: __( 'Alt text', 'dev3-ai-product-image-studio-for-woocommerce' ), maxLength: 125 },
	{ key: 'title', label: __( 'Title', 'dev3-ai-product-image-studio-for-woocommerce' ), maxLength: 120 },
	{ key: 'caption', label: __( 'Caption', 'dev3-ai-product-image-studio-for-woocommerce' ), multiline: true, maxLength: 300 },
];

/**
 * One result: compare slider (image) or draft review (alt text), with approve / reject / revert.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.result     Result.
 * @param {Function} props.onChange   ( updated ) => void.
 * @param {Object}   props.labels     Operation labels.
 * @param {boolean}  props.showProduct Show the product name.
 * @param {boolean}  props.selected   Selection state (review queue).
 * @param {Function} props.onSelect   ( bool ) => void.
 * @return {Element} Card.
 */
export default function ResultCard( { result, onChange, labels = {}, showProduct = false, selected, onSelect } ) {
	const toast = useToast();
	const [ mode, setMode ] = useState( result.apply_mode || 'replace' );
	const [ busy, setBusy ] = useState( '' );
	const pending = result.status === 'pending';
	const label = ( result.meta && result.meta.label ) || opLabel( result.operation, labels );

	const act = ( action, data = {} ) => {
		setBusy( action );
		return request( `results/${ result.id }/${ action }`, { method: 'POST', data } )
			.then( ( next ) => {
				onChange( next );
				const msg = {
					approve: __( 'Approved. The new image is live.', 'dev3-ai-product-image-studio-for-woocommerce' ),
					reject: __( 'Rejected and discarded.', 'dev3-ai-product-image-studio-for-woocommerce' ),
					revert: __( 'Reverted. The original is back.', 'dev3-ai-product-image-studio-for-woocommerce' ),
				};
				toast.success( result.kind === 'alt' && action === 'approve' ? __( 'Alt text saved.', 'dev3-ai-product-image-studio-for-woocommerce' ) : msg[ action ] );
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	const head = (
		<div className="dev3is-rcard__head">
			{ onSelect && pending && <Checkbox label={ sprintf( /* translators: %d: result id */ __( 'Select result %d', 'dev3-ai-product-image-studio-for-woocommerce' ), result.id ) } checked={ !! selected } onChange={ onSelect } className="dev3is-hidelabel" /> }
			<div className="dev3is-rcard__titles">
				<strong>{ label }</strong>
				{ showProduct && <span className="dev3is-muted">{ result.product_name || ( result.before && result.before.title ) }</span> }
			</div>
			<StatusBadge status={ result.status } />
		</div>
	);

	if ( result.kind === 'alt' ) {
		return (
			<article className={ `dev3is-rcard is-alt is-${ result.status }` }>
				{ head }
				<div className="dev3is-rcard__alt">
					{ result.before && <img src={ result.before.thumb } alt="" width="72" height="72" className="dev3is-rcard__altimg" /> }
					{ pending ? (
						<AiDraftReview
							fields={ ALT_FIELDS }
							draft={ { alt: '', title: '', caption: '', ...( result.alt || {} ) } }
							original={ result.before ? { alt: result.before.alt, title: result.before.title, caption: result.before.caption } : undefined }
							onAccept={ ( v ) => act( 'approve', v ) }
							onReject={ () => act( 'reject' ) }
							accepting={ busy === 'approve' }
							acceptLabel={ __( 'Save alt text', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							meta={ result.meta && result.meta.model ? { model: result.meta.model, tokens: result.meta.tokens } : undefined }
						/>
					) : (
						<div className="dev3is-rcard__alttext">
							<p>{ result.alt && result.alt.alt }</p>
							{ result.status === 'approved' && (
								<Button size="sm" variant="ghost" icon="refresh" loading={ busy === 'revert' } onClick={ () => act( 'revert' ) }>
									{ __( 'Revert', 'dev3-ai-product-image-studio-for-woocommerce' ) }
								</Button>
							) }
						</div>
					) }
				</div>
			</article>
		);
	}

	return (
		<article className={ `dev3is-rcard is-${ result.status }` }>
			{ head }
			{ result.after ? (
				<CompareSlider before={ result.before } after={ result.after } />
			) : (
				<p className="dev3is-muted">{ __( 'The result image was deleted.', 'dev3-ai-product-image-studio-for-woocommerce' ) }</p>
			) }
			<div className="dev3is-rcard__meta">
				{ result.after && <Badge>{ result.after.mime.replace( 'image/', '' ).toUpperCase() }</Badge> }
				{ result.after && <span>{ formatBytes( result.after.filesize ) }</span> }
				{ result.meta && result.meta.model && <span>{ result.meta.model }</span> }
				{ result.meta && result.meta.ms ? <span>{ sprintf( /* translators: %s: seconds */ __( '%ss', 'dev3-ai-product-image-studio-for-woocommerce' ), ( result.meta.ms / 1000 ).toFixed( 1 ) ) }</span> : null }
				{ result.meta && result.meta.variant > 1 && <span>{ sprintf( /* translators: %d: variant */ __( 'Variant %d', 'dev3-ai-product-image-studio-for-woocommerce' ), result.meta.variant ) }</span> }
			</div>
			{ pending && result.after && (
				<div className="dev3is-rcard__actions">
					<Select label={ __( 'When approved', 'dev3-ai-product-image-studio-for-woocommerce' ) } hideLabel value={ mode } onChange={ setMode } options={ result.product_id ? MODES : MODES.filter( ( m ) => m.value === 'library' ) } />
					<Inline gap={ 8 }>
						<Button variant="ghost" icon="x" loading={ busy === 'reject' } disabled={ !! busy } onClick={ () => act( 'reject' ) }>
							{ __( 'Reject', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
						<Button variant="primary" icon="check" loading={ busy === 'approve' } disabled={ !! busy } onClick={ () => act( 'approve', { mode: result.product_id ? mode : 'library' } ) }>
							{ __( 'Approve', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
					</Inline>
				</div>
			) }
			{ result.status === 'approved' && (
				<div className="dev3is-rcard__actions">
					<span className="dev3is-muted">{ ( MODES.find( ( m ) => m.value === result.apply_mode ) || {} ).label }</span>
					<Inline gap={ 8 }>
						{ result.after && (
							<Button size="sm" variant="ghost" icon="external" href={ result.after.url } target="_blank" rel="noreferrer">
								{ __( 'Full size', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>
						) }
						{ result.apply_mode !== 'library' && (
							<Button size="sm" icon="refresh" loading={ busy === 'revert' } onClick={ () => act( 'revert' ) }>
								{ __( 'Revert', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>
						) }
					</Inline>
				</div>
			) }
		</article>
	);
}
