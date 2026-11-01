import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Badge, Button, Card, Columns, ErrorNotice, FormGrid, JobProgress, Notice, PageHeader, ResponsiveTable, Select, Stack, formatNumber, useToast } from '../core3/ui';
import { ALL_OPS, OperationOptions, defaultOptions, isAi, opLabel, optionsReady } from './ops';
import { FILTERS } from './Library';
import { clearSelection, getSelection } from './store';

const SCOPES = [
	{ value: 'featured', label: __( 'Featured image', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'gallery', label: __( 'Gallery images', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'variations', label: __( 'Variation images', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'all', label: __( 'All images', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

const STATUS_TONE = { queued: 'neutral', running: 'info', done: 'success', failed: 'danger', cancelled: 'warning' };

/**
 * Batch mode: pick products + an operation or recipe, run it in the background, review the results.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.boot     Bootstrap.
 * @param {Array}    props.params   [ 'selection' ] | [ 'products', '1,2' ].
 * @param {Function} props.navigate Navigate.
 * @param {Function} props.reload   Reload bootstrap.
 * @param {Function} props.onUsage  Usage refresh.
 * @return {Element} Screen.
 */
export default function BatchScreen( { boot, params = [], navigate, reload, onUsage } ) {
	const toast = useToast();
	const selection = useMemo( () => ( params[ 0 ] === 'selection' ? getSelection() : { targets: [], labels: {} } ), [ params ] );
	const productIds = params[ 0 ] === 'products' && params[ 1 ] ? params[ 1 ].split( ',' ).map( Number ).filter( Boolean ) : [];
	const initialSource = selection.targets.length ? 'selection' : productIds.length ? 'products' : 'category';
	const [ source, setSource ] = useState( initialSource );
	const [ category, setCategory ] = useState( boot.categories.length ? String( boot.categories[ 0 ].value ) : '' );
	const [ scope, setScope ] = useState( boot.settings.batch_scope || 'featured' );
	const [ filter, setFilter ] = useState( 'all' );
	const [ choice, setChoice ] = useState( 'op:packshot' );
	const [ options, setOptions ] = useState( () => defaultOptions( 'packshot', boot.settings ) );
	const [ estimate, setEstimate ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ starting, setStarting ] = useState( false );
	const [ recent, setRecent ] = useState( null );
	const timer = useRef();

	const isRecipe = choice.startsWith( 'recipe:' );
	const recipe = isRecipe ? boot.recipes.recipes.find( ( r ) => `recipe:${ r.id }` === choice ) : null;
	const op = recipe ? recipe.operation : choice.replace( 'op:', '' );
	const aiReady = boot.ai.enabled && boot.ai.key_set && ! boot.ai.cap_reached;

	const body = () => {
		const b = { scope, filter, operation: op, options };
		if ( recipe ) {
			b.recipe = recipe.id;
			b.options = {};
		}
		if ( source === 'selection' ) {
			b.targets = selection.targets;
		} else if ( source === 'products' ) {
			b.product_ids = productIds;
		} else if ( source === 'category' ) {
			b.category = Number( category );
		} else {
			b.all = true;
		}
		return b;
	};

	const loadRecent = () => request( 'batches' ).then( setRecent ).catch( () => setRecent( [] ) );
	useEffect( () => {
		loadRecent();
	}, [] );

	useEffect( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			request( 'batch', { method: 'POST', data: { ...body(), dry_run: true } } )
				.then( setEstimate )
				.catch( () => setEstimate( null ) );
		}, 300 );
		return () => clearTimeout( timer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ source, category, scope, filter, choice, options.variants ] );

	const choose = ( value ) => {
		setChoice( value );
		if ( value.startsWith( 'op:' ) ) {
			setOptions( defaultOptions( value.slice( 3 ), boot.settings ) );
		}
	};

	const start = () => {
		setStarting( true );
		setError( null );
		request( 'batch', { method: 'POST', data: body() } )
			.then( ( j ) => {
				setJob( j );
				if ( source === 'selection' ) {
					clearSelection();
				}
				toast.success( __( 'Batch started. You can leave this page; it keeps running.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
				loadRecent();
			} )
			.catch( setError )
			.finally( () => setStarting( false ) );
	};

	const sources = [
		...( selection.targets.length ? [ { value: 'selection', label: sprintf( /* translators: %d: images */ _n( 'Selected in the library (%d image)', 'Selected in the library (%d images)', selection.targets.length, 'dev3-ai-product-image-studio-for-woocommerce' ), selection.targets.length ) } ] : [] ),
		...( productIds.length ? [ { value: 'products', label: sprintf( /* translators: %d: products */ _n( 'Chosen product (%d)', 'Chosen products (%d)', productIds.length, 'dev3-ai-product-image-studio-for-woocommerce' ), productIds.length ) } ] : [] ),
		{ value: 'category', label: __( 'A product category', 'dev3-ai-product-image-studio-for-woocommerce' ) },
		{ value: 'all', label: __( 'All products', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	];
	const opChoices = [
		{ label: __( 'Recipes', 'dev3-ai-product-image-studio-for-woocommerce' ), options: boot.recipes.recipes.map( ( r ) => ( { value: `recipe:${ r.id }`, label: r.name } ) ) },
		{ label: __( 'DEV3 AI edits', 'dev3-ai-product-image-studio-for-woocommerce' ), options: ALL_OPS.filter( ( o ) => o.ai ).map( ( o ) => ( { value: `op:${ o.id }`, label: o.label } ) ) },
		{ label: __( 'Without AI', 'dev3-ai-product-image-studio-for-woocommerce' ), options: ALL_OPS.filter( ( o ) => ! o.ai ).map( ( o ) => ( { value: `op:${ o.id }`, label: o.label } ) ) },
	];
	const blocked = isAi( op ) && ! aiReady;
	const canStart = estimate && estimate.count > 0 && ! blocked && ( recipe || optionsReady( op, options ) ) && ( source !== 'category' || category );

	return (
		<>
			<PageHeader title={ __( 'Batch', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Run one edit or recipe over many product images in the background. Every result waits in the review queue.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
			{ job ? (
				<Card title={ sprintf( /* translators: %s: operation */ __( 'Running: %s', 'dev3-ai-product-image-studio-for-woocommerce' ), recipe ? recipe.name : opLabel( op, boot.labels ) ) }>
					<Stack gap={ 12 }>
						<JobProgress
							jobId={ job.id }
							job={ job }
							title={ __( 'Batch progress', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							onChange={ () => onUsage() }
							onDone={ ( j ) => {
								reload();
								loadRecent();
								if ( j.failed ) {
									toast.warning( __( 'Batch finished with some failures. You can retry them.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
								} else {
									toast.success( __( 'Batch finished.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
								}
							} }
						/>
						<div>
							<Button variant="primary" iconRight="chevronRight" onClick={ () => navigate( `review/job/${ job.id }` ) } disabled={ op === 'audit' }>
								{ __( 'Review results', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>{ ' ' }
							<Button variant="ghost" onClick={ () => setJob( null ) }>
								{ __( 'Start another batch', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>
						</div>
					</Stack>
				</Card>
			) : (
				<Columns min={ 320 }>
					<Card title={ __( '1. Which images', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
						<FormGrid>
							<Select label={ __( 'Products', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ source } onChange={ setSource } options={ sources } />
							{ source === 'category' && (
								<Select label={ __( 'Category', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ category } onChange={ setCategory } options={ boot.categories.length ? boot.categories.map( ( c ) => ( { value: String( c.value ), label: c.label } ) ) : [ { value: '', label: __( 'No categories', 'dev3-ai-product-image-studio-for-woocommerce' ) } ] } />
							) }
							{ source !== 'selection' && <Select label={ __( 'Images', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ scope } onChange={ setScope } options={ SCOPES } /> }
							{ source !== 'selection' && <Select label={ __( 'Only images that are', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ filter } onChange={ setFilter } options={ FILTERS.filter( ( f ) => f.id !== 'pending' ).map( ( f ) => ( { value: f.id, label: f.id === 'all' ? __( 'Any', 'dev3-ai-product-image-studio-for-woocommerce' ) : f.label } ) ) } /> }
						</FormGrid>
					</Card>
					<Card title={ __( '2. What to do', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
						<Stack gap={ 12 }>
							<Select label={ __( 'Edit or recipe', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ choice } onChange={ choose } options={ opChoices } />
							{ recipe ? (
								<p className="dev3is-muted">{ sprintf( /* translators: %s: operation */ __( 'Recipe: %s with its saved options. Edit recipes on the Recipes screen.', 'dev3-ai-product-image-studio-for-woocommerce' ), opLabel( recipe.operation, boot.labels ) ) }</p>
							) : (
								<OperationOptions op={ op } value={ options } onChange={ setOptions } scenes={ boot.recipes.scenes } webp={ boot.webp } />
							) }
						</Stack>
					</Card>
				</Columns>
			) }
			{ ! job && (
				<Card tone={ isAi( op ) ? 'ai' : undefined }>
					<div className="dev3is-runbar">
						<span aria-live="polite">
							{ estimate ? (
								<>
									<strong>{ sprintf( /* translators: %s: images */ _n( '%s image', '%s images', estimate.count, 'dev3-ai-product-image-studio-for-woocommerce' ), formatNumber( estimate.count ) ) }</strong>
									{ estimate.ai_calls > 0 && <> · { sprintf( /* translators: %s: calls */ _n( '%s AI image request', '%s AI image requests', estimate.ai_calls, 'dev3-ai-product-image-studio-for-woocommerce' ), formatNumber( estimate.ai_calls ) ) }</> }
								</>
							) : (
								<span className="dev3is-muted">{ __( 'Counting images…', 'dev3-ai-product-image-studio-for-woocommerce' ) }</span>
							) }
						</span>
						{ isAi( op ) ? (
							<AiButton loading={ starting } disabled={ ! canStart || starting } onClick={ start }>
								{ __( 'Start batch with DEV3 AI', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</AiButton>
						) : (
							<Button variant="primary" icon="bolt" loading={ starting } disabled={ ! canStart || starting } onClick={ start }>
								{ __( 'Start batch', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>
						) }
					</div>
					{ blocked && <Notice tone="ai">{ __( 'Connect DEV3 AI in API Config to run AI batches. Batches without AI work now.', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Notice> }
					{ estimate && estimate.count === 0 && <Notice tone="warning">{ __( 'No product images match this selection.', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Notice> }
					<ErrorNotice error={ error } onDismiss={ () => setError( null ) } />
				</Card>
			) }

			<Card title={ __( 'Recent batches', 'dev3-ai-product-image-studio-for-woocommerce' ) } flush>
				<ResponsiveTable
					caption={ __( 'Recent batches', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					rows={ recent || [] }
					loading={ recent === null }
					columns={ [
						{ key: 'label', label: __( 'Batch', 'dev3-ai-product-image-studio-for-woocommerce' ), primary: true, render: ( j ) => <strong>{ `#${ j.id } ${ j.label }` }</strong> },
						{ key: 'status', label: __( 'Status', 'dev3-ai-product-image-studio-for-woocommerce' ), render: ( j ) => <Badge tone={ STATUS_TONE[ j.status ] || 'neutral' }>{ j.status }</Badge> },
						{ key: 'progress', label: __( 'Progress', 'dev3-ai-product-image-studio-for-woocommerce' ), render: ( j ) => sprintf( /* translators: 1: done, 2: total, 3: failed */ __( '%1$d / %2$d (%3$d failed)', 'dev3-ai-product-image-studio-for-woocommerce' ), j.done, j.total, j.failed ) },
						{ key: 'created_at', label: __( 'Started', 'dev3-ai-product-image-studio-for-woocommerce' ), hideOnMobile: true, render: ( j ) => new Date( j.created_at ).toLocaleString() },
					] }
					rowActions={ ( j ) => (
						<Button size="sm" variant="ghost" href={ `#/review/job/${ j.id }` } disabled={ j.args && j.args.operation === 'audit' }>
							{ __( 'Results', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
					) }
					empty={ { icon: 'bolt', title: __( 'No batches yet', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Your batches and their progress show up here.', 'dev3-ai-product-image-studio-for-woocommerce' ) } }
				/>
			</Card>
		</>
	);
}
