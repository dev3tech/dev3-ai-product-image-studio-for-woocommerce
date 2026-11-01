import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, ErrorNotice, Inline, Notice, PageHeader, ResponsiveTable, Select, Tabs, TextField, formatNumber, useToast } from '../core3/ui';
import { ImageFlags, formatBytes } from './parts';
import { setSelection } from './store';

const PER_PAGE = 20;

export const FILTERS = [
	{ id: 'all', label: __( 'All', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'missing_alt', label: __( 'Missing alt', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'low_res', label: __( 'Low-res', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'not_white', label: __( 'Not on white', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'not_square', label: __( 'Not square', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'pending', label: __( 'In review', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

const SCOPES = [
	{ value: 'all', label: __( 'All images', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'featured', label: __( 'Featured only', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'gallery', label: __( 'Gallery only', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ value: 'variations', label: __( 'Variations only', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

const stop = ( e ) => e.stopPropagation();

/**
 * Product image library with filters, search and multi-select for batches.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.boot     Bootstrap.
 * @param {Function} props.navigate Navigate.
 * @param {Array}    props.params   Route params ([ filter ]).
 * @return {Element} Screen.
 */
export default function Library( { boot, navigate, params = [] } ) {
	const toast = useToast();
	const filter = FILTERS.some( ( f ) => f.id === params[ 0 ] ) ? params[ 0 ] : 'all';
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ category, setCategory ] = useState( '' );
	const [ scope, setScope ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ selected, setSelected ] = useState( {} );
	const timer = useRef();

	useEffect( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => setQuery( search.trim() ), 350 );
		return () => clearTimeout( timer.current );
	}, [ search ] );

	useEffect( () => setPage( 1 ), [ filter, query, category, scope ] );

	useEffect( () => {
		setLoading( true );
		setError( null );
		const q = new URLSearchParams( { filter, search: query, category, scope, page: String( page ), per_page: String( PER_PAGE ) } );
		request( `images?${ q.toString() }` )
			.then( setData )
			.catch( setError )
			.finally( () => setLoading( false ) );
	}, [ filter, query, category, scope, page ] );

	const rows = data ? data.rows : [];
	const count = Object.keys( selected ).length;
	const pageAll = rows.length > 0 && rows.every( ( r ) => selected[ r.key ] );
	const toggle = ( row, on ) =>
		setSelected( ( cur ) => {
			const next = { ...cur };
			if ( on ) {
				next[ row.key ] = row.product_name;
			} else {
				delete next[ row.key ];
			}
			return next;
		} );
	const togglePage = ( on ) => rows.forEach( ( r ) => toggle( r, on ) );
	const open = ( row ) => navigate( `studio/${ row.attachment.id }/${ row.product_id }/${ row.slot }` );
	const batch = () => {
		setSelection( Object.keys( selected ), selected );
		navigate( 'batch/selection' );
	};
	const audit = () =>
		request( 'batch', { method: 'POST', data: { all: true, scope: 'all', operation: 'audit' } } )
			.then( () => {
				toast.success( __( 'Audit started. Follow it on the Batch screen.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
				navigate( 'batch' );
			} )
			.catch( ( e ) => toast.error( e ) );

	const columns = [
		{
			key: 'select',
			label: __( 'Select', 'dev3-ai-product-image-studio-for-woocommerce' ),
			width: 44,
			render: ( r ) => (
				// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
				<span onClick={ stop } className="dev3is-selcell">
					{ /* translators: %s: product name */ }
					<Checkbox label={ sprintf( __( 'Select %s', 'dev3-ai-product-image-studio-for-woocommerce' ), r.product_name ) } checked={ !! selected[ r.key ] } onChange={ ( on ) => toggle( r, on ) } className="dev3is-hidelabel" />
				</span>
			),
		},
		{
			key: 'image',
			label: __( 'Image', 'dev3-ai-product-image-studio-for-woocommerce' ),
			width: 72,
			render: ( r ) => ( r.attachment ? <img className="dev3is-libthumb" src={ r.attachment.thumb } alt={ r.attachment.alt } width="56" height="56" loading="lazy" /> : null ),
		},
		{
			key: 'product_name',
			label: __( 'Product', 'dev3-ai-product-image-studio-for-woocommerce' ),
			primary: true,
			render: ( r ) => (
				<span className="dev3is-prodcell">
					<strong>{ r.product_name }</strong>
					<span className="dev3is-muted">
						{ r.slot_label }
						{ r.sku ? ` · ${ r.sku }` : '' }
					</span>
				</span>
			),
		},
		{
			key: 'size',
			label: __( 'Size', 'dev3-ai-product-image-studio-for-woocommerce' ),
			nowrap: true,
			render: ( r ) => (
				<span className="dev3is-prodcell">
					<span>{ r.attachment ? `${ r.attachment.width }×${ r.attachment.height }` : '—' }</span>
					<span className="dev3is-muted">{ r.attachment ? formatBytes( r.attachment.filesize ) : '' }</span>
				</span>
			),
		},
		{
			key: 'alt',
			label: __( 'Alt text', 'dev3-ai-product-image-studio-for-woocommerce' ),
			hideOnMobile: true,
			render: ( r ) => ( r.attachment && r.attachment.alt ? <span className="dev3is-clamp">{ r.attachment.alt }</span> : <span className="dev3is-muted">—</span> ),
		},
		{
			key: 'flags',
			label: __( 'Health', 'dev3-ai-product-image-studio-for-woocommerce' ),
			render: ( r ) => (
				<>
					<ImageFlags flags={ r.flags } />
					{ r.pending > 0 && (
						<Badge tone="info">
							{ /* translators: %d: results */ }
							{ sprintf( _n( '%d in review', '%d in review', r.pending, 'dev3-ai-product-image-studio-for-woocommerce' ), r.pending ) }
						</Badge>
					) }
				</>
			),
		},
	];

	return (
		<>
			<PageHeader
				title={ __( 'Product images', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				description={ __( 'Every featured, gallery and variation image in your store. Select images to batch them, or open one in the studio.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				actions={
					<Button variant="primary" icon="bolt" disabled={ ! count } onClick={ batch }>
						{ count
							? sprintf( /* translators: %d: selected images */ _n( 'Batch %d image', 'Batch %d images', count, 'dev3-ai-product-image-studio-for-woocommerce' ), count )
							: __( 'Batch selected', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					</Button>
				}
			/>
			<Tabs variant="pills" label={ __( 'Filter images', 'dev3-ai-product-image-studio-for-woocommerce' ) } tabs={ FILTERS } value={ filter } onChange={ ( id ) => navigate( id === 'all' ? 'library' : `library/${ id }` ) } />
			{ filter === 'not_white' && boot.stats.unaudited > 0 && (
				<Notice tone="info" title={ __( 'Some images are not audited yet', 'dev3-ai-product-image-studio-for-woocommerce' ) } actions={ <Button size="sm" onClick={ audit }>{ __( 'Audit all images', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Button> }>
					{ __( 'Background whiteness is measured once per image and cached. This page audits up to 150 new images per load; run a background audit to measure the rest.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				</Notice>
			) }
			<Card flush>
				<div className="dev3is-toolbar">
					<TextField label={ __( 'Search products', 'dev3-ai-product-image-studio-for-woocommerce' ) } hideLabel value={ search } onChange={ setSearch } placeholder={ __( 'Search by product name or SKU…', 'dev3-ai-product-image-studio-for-woocommerce' ) } type="search" />
					<Select label={ __( 'Category', 'dev3-ai-product-image-studio-for-woocommerce' ) } hideLabel value={ category } onChange={ setCategory } options={ [ { value: '', label: __( 'All categories', 'dev3-ai-product-image-studio-for-woocommerce' ) }, ...boot.categories.map( ( c ) => ( { value: String( c.value ), label: c.label } ) ) ] } />
					<Select label={ __( 'Image type', 'dev3-ai-product-image-studio-for-woocommerce' ) } hideLabel value={ scope } onChange={ setScope } options={ SCOPES } />
				</div>
				<div className="dev3is-selbar">
					<Checkbox label={ __( 'Select all on this page', 'dev3-ai-product-image-studio-for-woocommerce' ) } checked={ pageAll } onChange={ togglePage } />
					<Inline gap={ 8 }>
						<span className="dev3is-muted" aria-live="polite">
							{ data ? sprintf( /* translators: %s: images */ _n( '%s image', '%s images', data.total, 'dev3-ai-product-image-studio-for-woocommerce' ), formatNumber( data.total ) ) : '' }
							{ count ? ` · ${ sprintf( /* translators: %d: selected */ __( '%d selected', 'dev3-ai-product-image-studio-for-woocommerce' ), count ) }` : '' }
						</span>
						{ count > 0 && (
							<Button size="sm" variant="ghost" onClick={ () => setSelected( {} ) }>
								{ __( 'Clear', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>
						) }
					</Inline>
				</div>
				{ error && <ErrorNotice error={ error } /> }
				<ResponsiveTable
					caption={ __( 'Product images', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					columns={ columns }
					rows={ rows }
					rowKey="key"
					loading={ loading && ! data }
					onRowClick={ open }
					rowActions={ ( r ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span onClick={ stop }>
							<Button size="sm" icon="edit" onClick={ () => open( r ) }>
								{ __( 'Open in studio', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							</Button>
						</span>
					) }
					pagination={ data ? { page, perPage: PER_PAGE, total: data.total, onPageChange: setPage } : undefined }
					empty={ {
						icon: 'search',
						title: filter === 'all' && ! query ? __( 'No product images yet', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Nothing matches', 'dev3-ai-product-image-studio-for-woocommerce' ),
						description: filter === 'all' && ! query ? __( 'Add images to your WooCommerce products and they appear here.', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Try another filter or search.', 'dev3-ai-product-image-studio-for-woocommerce' ),
					} }
				/>
			</Card>
		</>
	);
}
