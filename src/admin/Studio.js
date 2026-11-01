import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, EmptyState, ErrorNotice, Inline, Notice, PageHeader, Select, SkeletonCard, Stack, TextField, useToast } from '../core3/ui';
import { OPS, OperationOptions, defaultOptions, opLabel, optionsReady } from './ops';
import { ImageFlags, formatBytes } from './parts';
import ResultCard, { ALT_FIELDS } from './ResultCard';

/**
 * Single-image studio: original + audit, operation picker with options, results with compare slider,
 * alt text and history.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.boot    Bootstrap.
 * @param {Array}    props.params  [ attachment, product, slot ].
 * @param {Function} props.onUsage Refresh usage meter.
 * @param {Function} props.reload  Reload bootstrap.
 * @return {Element} Screen.
 */
export default function Studio( { boot, params = [], onUsage, reload } ) {
	const toast = useToast();
	const [ attachment, product = '0', slot = '' ] = params;
	const [ image, setImage ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ op, setOp ] = useState( 'packshot' );
	const [ options, setOptions ] = useState( () => defaultOptions( 'packshot', boot.settings ) );
	const [ recipe, setRecipe ] = useState( '' );
	const [ running, setRunning ] = useState( false );
	const [ runError, setRunError ] = useState( null );
	const [ results, setResults ] = useState( [] );
	const [ ideas, setIdeas ] = useState( null );
	const [ ideasBusy, setIdeasBusy ] = useState( false );
	const [ altDraft, setAltDraft ] = useState( null );
	const [ altBusy, setAltBusy ] = useState( false );
	const [ altError, setAltError ] = useState( null );
	const [ manualAlt, setManualAlt ] = useState( '' );

	const aiReady = boot.ai.enabled && boot.ai.key_set && ! boot.ai.cap_reached;
	const opDef = OPS.find( ( o ) => o.id === op );

	const load = () => {
		if ( ! attachment ) {
			return;
		}
		setError( null );
		request( `images/${ attachment }?product_id=${ product }&slot=${ encodeURIComponent( slot ) }` )
			.then( ( data ) => {
				setImage( data );
				setResults( data.history.filter( ( r ) => r.kind === 'image' ) );
				setManualAlt( data.attachment ? data.attachment.alt : '' );
			} )
			.catch( setError );
	};
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( load, [ attachment, product, slot ] );

	const pick = ( id ) => {
		setOp( id );
		setOptions( defaultOptions( id, boot.settings ) );
		setRecipe( '' );
		setRunError( null );
	};
	const useRecipe = ( id ) => {
		setRecipe( id );
		const r = boot.recipes.recipes.find( ( x ) => x.id === id );
		if ( r ) {
			setOp( r.operation );
			setOptions( { ...defaultOptions( r.operation, boot.settings ), ...r.options } );
		}
	};

	const run = () => {
		setRunning( true );
		setRunError( null );
		request( 'process', { method: 'POST', data: { attachment_id: Number( attachment ), product_id: Number( product ), slot: image.slot, operation: op, options } } )
			.then( ( res ) => {
				setResults( ( cur ) => [ ...res.results, ...cur ] );
				toast.success( res.results.length > 1 ? sprintf( /* translators: %d: results */ __( '%d new drafts ready for review.', 'dev3-ai-product-image-studio-for-woocommerce' ), res.results.length ) : __( 'New draft ready for review.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
				reload();
			} )
			.catch( setRunError )
			.finally( () => {
				setRunning( false );
				onUsage();
			} );
	};

	const suggestIdeas = () => {
		setIdeasBusy( true );
		request( 'scenes/ideas', { method: 'POST', data: { product_id: Number( product ) } } )
			.then( ( r ) => setIdeas( r.ideas ) )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => {
				setIdeasBusy( false );
				onUsage();
			} );
	};

	const suggestAlt = () => {
		setAltBusy( true );
		setAltError( null );
		request( 'alt/suggest', { method: 'POST', data: { attachment_id: Number( attachment ), product_id: Number( product ) } } )
			.then( setAltDraft )
			.catch( setAltError )
			.finally( () => {
				setAltBusy( false );
				onUsage();
			} );
	};

	const saveAlt = ( fields, source ) =>
		request( 'alt/apply', { method: 'POST', data: { attachment_id: Number( attachment ), product_id: Number( product ), slot: image.slot, source, ...fields } } )
			.then( () => {
				toast.success( __( 'Alt text saved.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
				setAltDraft( null );
				load();
				reload();
			} )
			.catch( ( e ) => toast.error( e ) );

	const updateResult = ( next ) => {
		setResults( ( cur ) => cur.map( ( r ) => ( r.id === next.id ? next : r ) ) );
		reload();
		if ( next.status === 'approved' || next.status === 'reverted' ) {
			load();
		}
	};

	if ( ! attachment ) {
		return (
			<>
				<PageHeader title={ __( 'Studio', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Edit one product image at a time and compare every result before it goes live.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
				<Card>
					<EmptyState icon="edit" title={ __( 'Pick an image to start', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Open any product image from the library, the product edit screen or the media library.', 'dev3-ai-product-image-studio-for-woocommerce' ) } action={ <Button variant="primary" href="#/library">{ __( 'Browse product images', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Button> } />
				</Card>
			</>
		);
	}
	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ load } />;
	}
	if ( ! image ) {
		return <SkeletonCard lines={ 8 } />;
	}
	const a = image.attachment;
	const pendingCount = results.filter( ( r ) => r.status === 'pending' ).length;

	return (
		<>
			<PageHeader
				eyebrow={ image.slot_label }
				title={ image.product_name || a.title }
				description={ __( 'Results are saved as new images. Approve one to use it in the product; the original stays in your media library.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				back={ { href: '#/library', label: __( 'Product images', 'dev3-ai-product-image-studio-for-woocommerce' ) } }
				actions={
					image.edit_url ? (
						<Button icon="external" href={ image.edit_url }>
							{ __( 'Edit product', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
					) : null
				}
			/>
			{ image.draft && <Notice tone="warning">{ __( 'This image is an unapproved result. Approve or reject it in the review queue before editing it further.', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Notice> }

			<div className="dev3is-studio">
				<div className="dev3is-studio__side">
					<Card title={ __( 'Original', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Never modified.', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
						<div className="dev3is-original is-checker">
							<img src={ a.large } alt={ a.alt } width={ a.width } height={ a.height } />
						</div>
						<dl className="dev3is-facts">
							<div>
								<dt>{ __( 'Size', 'dev3-ai-product-image-studio-for-woocommerce' ) }</dt>
								<dd>{ `${ a.width }×${ a.height }` }</dd>
							</div>
							<div>
								<dt>{ __( 'File', 'dev3-ai-product-image-studio-for-woocommerce' ) }</dt>
								<dd>{ `${ a.mime.replace( 'image/', '' ).toUpperCase() } · ${ formatBytes( a.filesize ) }` }</dd>
							</div>
							<div>
								<dt>{ __( 'White background', 'dev3-ai-product-image-studio-for-woocommerce' ) }</dt>
								<dd>{ image.flags.whiteness === null ? '—' : `${ Math.round( image.flags.whiteness * 100 ) }%` }</dd>
							</div>
						</dl>
						<ImageFlags flags={ image.flags } />
					</Card>

					<Card tone="ai" title={ __( 'Alt text', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Describe the photo for screen readers and search engines.', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
						<Stack gap={ 12 }>
							<TextField label={ __( 'Alt text', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ manualAlt } onChange={ setManualAlt } maxLength={ 125 } />
							<Inline gap={ 8 }>
								<Button size="sm" onClick={ () => saveAlt( { alt: manualAlt }, 'manual' ) } disabled={ manualAlt === a.alt }>
									{ __( 'Save', 'dev3-ai-product-image-studio-for-woocommerce' ) }
								</Button>
								<AiButton size="sm" loading={ altBusy } disabled={ ! aiReady || altBusy } onClick={ suggestAlt }>
									{ __( 'Write with DEV3 AI', 'dev3-ai-product-image-studio-for-woocommerce' ) }
								</AiButton>
							</Inline>
							<ErrorNotice error={ altError } onRetry={ suggestAlt } onDismiss={ () => setAltError( null ) } />
							{ altDraft && (
								<AiDraftReview
									fields={ ALT_FIELDS }
									draft={ altDraft.draft }
									original={ altDraft.original }
									meta={ { model: altDraft.model, tokens: altDraft.tokens, cached: altDraft.cached } }
									onAccept={ ( v ) => saveAlt( v, 'ai' ) }
									onReject={ () => setAltDraft( null ) }
									onRegenerate={ suggestAlt }
									regenerating={ altBusy }
									acceptLabel={ __( 'Save alt text', 'dev3-ai-product-image-studio-for-woocommerce' ) }
								/>
							) }
						</Stack>
					</Card>
				</div>

				<div className="dev3is-studio__main">
					<Card
						title={ __( 'Choose an edit', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						actions={
							<Select
								label={ __( 'Start from a recipe', 'dev3-ai-product-image-studio-for-woocommerce' ) }
								hideLabel
								value={ recipe }
								onChange={ useRecipe }
								options={ [ { value: '', label: __( 'Start from a recipe…', 'dev3-ai-product-image-studio-for-woocommerce' ) }, ...boot.recipes.recipes.map( ( r ) => ( { value: r.id, label: r.name } ) ) ] }
							/>
						}
					>
						<div className="dev3is-ops" role="radiogroup" aria-label={ __( 'Edit type', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
							{ OPS.map( ( o ) => (
								<button key={ o.id } type="button" role="radio" aria-checked={ op === o.id } className={ `dev3is-op${ op === o.id ? ' is-active' : '' }` } onClick={ () => pick( o.id ) }>
									<span className="dev3is-op__label">
										{ o.label }
										{ o.ai ? <Badge tone="brand">AI</Badge> : null }
									</span>
									<span className="dev3is-op__desc">{ o.description }</span>
								</button>
							) ) }
						</div>
						<div className="dev3is-opform">
							<OperationOptions op={ op } value={ options } onChange={ setOptions } scenes={ boot.recipes.scenes } webp={ boot.webp } />
							{ op === 'scene' && (
								<div className="dev3is-ideas">
									<Button size="sm" variant="ghost" icon="sparkles" loading={ ideasBusy } disabled={ ! aiReady || ! Number( product ) } onClick={ suggestIdeas }>
										{ __( 'Suggest scenes with DEV3 AI', 'dev3-ai-product-image-studio-for-woocommerce' ) }
									</Button>
									{ ideas && (
										<ul className="dev3is-chips">
											{ ideas.map( ( idea ) => (
												<li key={ idea.label }>
													<button type="button" className="dev3is-chip" title={ idea.prompt } onClick={ () => setOptions( { ...options, preset: '', prompt: idea.prompt } ) }>
														{ idea.label }
													</button>
												</li>
											) ) }
										</ul>
									) }
								</div>
							) }
						</div>
						{ opDef.ai && ! aiReady && (
							<Notice tone="ai" title={ __( 'DEV3 AI is not connected', 'dev3-ai-product-image-studio-for-woocommerce' ) } actions={ boot.canManage ? <Button size="sm" href="#/api">{ __( 'API Config', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Button> : null }>
								{ boot.ai.cap_reached ? __( 'AI paused: the monthly AI budget is reached.', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Add a Google Gemini API key to use AI edits. The tools marked without AI work now.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Notice>
						) }
						<ErrorNotice error={ runError } onRetry={ run } onDismiss={ () => setRunError( null ) } />
						<div className="dev3is-runbar">
							<span className="dev3is-muted">
								{ opDef.ai
									? __( 'Takes 10-30 seconds per image. The result is a new draft image.', 'dev3-ai-product-image-studio-for-woocommerce' )
									: __( 'Runs on your server in a second or two. The result is a new draft image.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</span>
							{ opDef.ai ? (
								<AiButton loading={ running } loadingText={ __( 'DEV3 AI is editing…', 'dev3-ai-product-image-studio-for-woocommerce' ) } disabled={ running || ! aiReady || ! optionsReady( op, options ) || image.draft } onClick={ run } />
							) : (
								<Button variant="primary" icon="check" loading={ running } disabled={ running || ! optionsReady( op, options ) || image.draft || ! boot.gd } onClick={ run }>
									{ sprintf( /* translators: %s: operation */ __( 'Create %s', 'dev3-ai-product-image-studio-for-woocommerce' ), opLabel( op ).toLowerCase() ) }
								</Button>
							) }
						</div>
					</Card>

					<Card title={ __( 'Results', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ pendingCount ? sprintf( /* translators: %d: count */ __( '%d waiting for your review. Drag the slider to compare.', 'dev3-ai-product-image-studio-for-woocommerce' ), pendingCount ) : __( 'Drag the slider to compare before and after.', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
						{ results.length ? (
							<div className="dev3is-results">
								{ results.map( ( r ) => (
									<ResultCard key={ r.id } result={ r } labels={ boot.labels } onChange={ updateResult } />
								) ) }
							</div>
						) : (
							<EmptyState compact icon="sparkles" title={ __( 'No results for this image yet', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Pick an edit above and run it. Results appear here as drafts.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
						) }
					</Card>
				</div>
			</div>
		</>
	);
}
