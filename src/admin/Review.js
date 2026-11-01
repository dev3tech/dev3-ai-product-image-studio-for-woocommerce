import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, Checkbox, EmptyState, ErrorNotice, Inline, PageHeader, Pagination, Select, SkeletonCard, Tabs, useToast } from '../core3/ui';
import { MODES } from './parts';
import ResultCard from './ResultCard';

const PER_PAGE = 12;

const KINDS = [
	{ id: 'all', label: __( 'Everything', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'image', label: __( 'Images', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'alt', label: __( 'Alt text', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

const STATUSES = [
	{ value: 'pending', label: __( 'Waiting for review', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'approved', label: __( 'Approved', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'rejected', label: __( 'Rejected', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'reverted', label: __( 'Reverted', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'all', label: __( 'All statuses', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

/**
 * Review queue: every draft from the studio and batches, with compare sliders and bulk decisions.
 *
 * @param {Object}   props        Props.
 * @param {Object}   props.boot   Bootstrap.
 * @param {Function} props.reload Reload bootstrap.
 * @param {Array}    props.params [ job id ].
 * @return {Element} Screen.
 */
export default function Review( { boot, reload, params = [] } ) {
	const toast = useToast();
	const job = params[ 0 ] === 'job' ? Number( params[ 1 ] ) : 0;
	const [ kind, setKind ] = useState( 'all' );
	const [ status, setStatus ] = useState( 'pending' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( {} );
	const [ mode, setMode ] = useState( boot.settings.default_mode || 'replace' );
	const [ busy, setBusy ] = useState( '' );
	const [ tick, setTick ] = useState( 0 );

	useEffect( () => setPage( 1 ), [ kind, status ] );
	useEffect( () => {
		setError( null );
		const q = new URLSearchParams( { status, kind: kind === 'all' ? '' : kind, page: String( page ), per_page: String( PER_PAGE ), job_id: String( job || '' ) } );
		request( `results?${ q.toString() }` )
			.then( ( d ) => {
				setData( d );
				setSelected( {} );
			} )
			.catch( setError );
	}, [ kind, status, page, job, tick ] );

	const rows = data ? data.rows : [];
	const pendingRows = rows.filter( ( r ) => r.status === 'pending' );
	const ids = Object.keys( selected ).filter( ( k ) => selected[ k ] );
	const allOn = pendingRows.length > 0 && pendingRows.every( ( r ) => selected[ r.id ] );

	const update = ( next ) => {
		setData( ( d ) => ( { ...d, rows: d.rows.map( ( r ) => ( r.id === next.id ? next : r ) ) } ) );
		reload();
	};
	const bulk = ( action ) => {
		setBusy( action );
		request( 'results/bulk', { method: 'POST', data: { ids: ids.map( Number ), action, mode } } )
			.then( ( res ) => {
				if ( res.errors.length ) {
					toast.warning( sprintf( /* translators: 1: done, 2: failed, 3: first error */ __( '%1$d done, %2$d could not be applied: %3$s', 'dev3-ai-product-image-studio-for-woocommerce' ), res.done, res.errors.length, res.errors[ 0 ].message ) );
				} else {
					toast.success( sprintf( /* translators: %d: count */ _n( '%d result updated.', '%d results updated.', res.done, 'dev3-ai-product-image-studio-for-woocommerce' ), res.done ) );
				}
				setTick( ( t ) => t + 1 );
				reload();
			} )
			.catch( ( e ) => toast.error( e ) )
			.finally( () => setBusy( '' ) );
	};

	return (
		<>
			<PageHeader
				title={ __( 'Review', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				description={ job ? sprintf( /* translators: %d: batch id */ __( 'Results of batch #%d.', 'dev3-ai-product-image-studio-for-woocommerce' ), job ) : __( 'Approve the results you like. Approved images replace the original in the product (or join its gallery); rejected ones are discarded.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				back={ job ? { href: '#/review', label: __( 'All results', 'dev3-ai-product-image-studio-for-woocommerce' ) } : undefined }
			/>
			<div className="dev3is-reviewbar">
				<Tabs variant="pills" label={ __( 'Result type', 'dev3-ai-product-image-studio-for-woocommerce' ) } tabs={ KINDS } value={ kind } onChange={ setKind } />
				<Select label={ __( 'Status', 'dev3-ai-product-image-studio-for-woocommerce' ) } hideLabel value={ status } onChange={ setStatus } options={ STATUSES } />
			</div>
			{ pendingRows.length > 0 && (
				<Card className="dev3is-bulkcard">
					<div className="dev3is-bulk">
						<Checkbox
							label={ __( 'Select all on this page', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							checked={ allOn }
							onChange={ ( on ) => setSelected( on ? Object.fromEntries( pendingRows.map( ( r ) => [ r.id, true ] ) ) : {} ) }
						/>
						<Inline gap={ 8 } align="end">
							<Select label={ __( 'Approve as', 'dev3-ai-product-image-studio-for-woocommerce' ) } hideLabel value={ mode } onChange={ setMode } options={ MODES } />
							<Button variant="ghost" icon="x" disabled={ ! ids.length || !! busy } loading={ busy === 'reject' } onClick={ () => bulk( 'reject' ) }>
								{ __( 'Reject selected', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>
							<Button variant="primary" icon="check" disabled={ ! ids.length || !! busy } loading={ busy === 'approve' } onClick={ () => bulk( 'approve' ) }>
								{ ids.length ? sprintf( /* translators: %d: count */ __( 'Approve %d', 'dev3-ai-product-image-studio-for-woocommerce' ), ids.length ) : __( 'Approve selected', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>
						</Inline>
					</div>
				</Card>
			) }
			{ error && <ErrorNotice error={ error } onRetry={ () => setTick( ( t ) => t + 1 ) } /> }
			{ ! data && ! error && <SkeletonCard lines={ 6 } /> }
			{ data && ! rows.length && (
				<Card>
					<EmptyState
						icon="inbox"
						title={ status === 'pending' ? __( 'Nothing to review', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'No results here', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						description={ status === 'pending' ? __( 'New drafts from the studio and from batches land here.', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Try another status or type.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						action={ <Button href="#/library">{ __( 'Open product images', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Button> }
					/>
				</Card>
			) }
			{ rows.length > 0 && (
				<div className="dev3is-results is-grid">
					{ rows.map( ( r ) => (
						<ResultCard key={ r.id } result={ r } labels={ boot.labels } onChange={ update } showProduct selected={ !! selected[ r.id ] } onSelect={ ( on ) => setSelected( ( s ) => ( { ...s, [ r.id ]: on } ) ) } />
					) ) }
				</div>
			) }
			{ data && data.total > PER_PAGE && <Pagination page={ page } perPage={ PER_PAGE } total={ data.total } onPageChange={ setPage } /> }
		</>
	);
}
