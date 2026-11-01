import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import CompareSlider from '../../src/admin/CompareSlider';
import Studio from '../../src/admin/Studio';
import Review from '../../src/admin/Review';
import Library from '../../src/admin/Library';
import BatchScreen from '../../src/admin/BatchScreen';
import Dashboard from '../../src/admin/Dashboard';
import ResultCard from '../../src/admin/ResultCard';
import { OPS, defaultOptions, isAi, optionsReady } from '../../src/admin/ops';
import { formatBytes } from '../../src/admin/parts';
import { getSelection, setSelection } from '../../src/admin/store';
import { initCompare } from '../../src/frontend/compare';
import { act, change, click, render } from './render';

const img = ( id, w = 800, h = 600 ) => ( { id, url: `/u/${ id }.jpg`, large: `/u/${ id }-l.jpg`, thumb: `/u/${ id }-t.jpg`, width: w, height: h, mime: 'image/jpeg', filesize: 120000, alt: 'Mug', title: 'Mug', caption: '' } );

const BOOT = {
	settings: { bg_color: '#ffffff', fill_ratio: 85, shadow: true, low_res: 800, default_mode: 'replace', batch_scope: 'featured', jpeg_quality: 90, alt_template: '{product}' },
	labels: { packshot: 'White pack shot', square: 'Square canvas' },
	stats: { images: 12, products: 6, missing_alt: 3, low_res: 2, not_square: 4, not_white: 5, unaudited: 1 },
	counts: { pending: 2, approved: 1, rejected: 0, reverted: 0, failed: 0 },
	recipes: { recipes: [ { id: 'marketplace-white', name: 'Marketplace white 1:1', operation: 'packshot', options: { fill: 85 } } ], scenes: [ { id: 'kitchen', label: 'Kitchen counter', prompt: 'on a counter' } ] },
	ai: { enabled: true, key_set: true, cap_reached: false, public: true },
	gd: true,
	webp: true,
	canManage: true,
	categories: [ { value: 5, label: 'Mugs (6)' } ],
	recent: [],
};

const pendingResult = ( over = {} ) => ( { id: 7, product_id: 3, product_name: 'Blue Mug', source_id: 11, result_id: 21, kind: 'image', operation: 'packshot', status: 'pending', apply_mode: 'replace', slot: 'featured', before: img( 11 ), after: img( 21, 1024, 1024 ), meta: { label: 'White pack shot', model: 'gemini-2.5-flash-image', ms: 9000, variant: 1 }, ...over } );

const wrap = ( el ) => <ToastProvider>{ el }</ToastProvider>;

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
	window.location.hash = '';
} );

describe( 'ops helpers', () => {
	it( 'knows which operations need AI and their defaults', () => {
		expect( OPS.map( ( o ) => o.id ) ).toEqual( [ 'remove_bg', 'packshot', 'scene', 'upscale', 'edit', 'square', 'local_upscale', 'flatten', 'convert' ] );
		expect( isAi( 'scene' ) ).toBe( true );
		expect( isAi( 'square' ) ).toBe( false );
		expect( isAi( 'alt' ) ).toBe( true );
		expect( defaultOptions( 'packshot', { bg_color: '#eeeeee', fill_ratio: 70 } ) ).toEqual( { color: '#eeeeee', fill: 70, size: 0, shadow: true } );
		expect( defaultOptions( 'scene' ).variants ).toBe( 1 );
	} );

	it( 'validates required options', () => {
		expect( optionsReady( 'edit', { instruction: '  ' } ) ).toBe( false );
		expect( optionsReady( 'edit', { instruction: 'remove sticker' } ) ).toBe( true );
		expect( optionsReady( 'scene', { preset: '', prompt: '' } ) ).toBe( false );
		expect( optionsReady( 'scene', { preset: 'kitchen' } ) ).toBe( true );
		expect( optionsReady( 'packshot', {} ) ).toBe( true );
	} );

	it( 'formats bytes and hands the selection to the batch screen', () => {
		expect( formatBytes( 0 ) ).toBe( '—' );
		expect( formatBytes( 2048 ) ).toBe( '2 KB' );
		expect( formatBytes( 3 * 1024 * 1024 ) ).toBe( '3.0 MB' );
		setSelection( [ '1:2:featured' ], { '1:2:featured': 'Mug' } );
		expect( getSelection().targets ).toEqual( [ '1:2:featured' ] );
	} );
} );

describe( 'CompareSlider', () => {
	it( 'moves with the range input and switches to side by side', async () => {
		const view = await render( <CompareSlider before={ img( 1 ) } after={ img( 2 ) } /> );
		const range = view.$( '.dev3is-cmp__range' );
		expect( range.getAttribute( 'aria-label' ) ).toBe( 'Compare Before and After' );
		await change( range, '20' );
		expect( view.$( '.dev3is-cmp__frame' ).style.getPropertyValue( '--dev3is-pos' ) ).toBe( '20%' );
		await click( view.getByText( 'Side by side' ) );
		expect( view.$( '.dev3is-cmp__side' ) ).not.toBeNull();
		expect( view.$( '.dev3is-cmp__range' ) ).toBeNull();
		view.unmount();
	} );

	it( 'renders nothing without both images', async () => {
		const view = await render( <CompareSlider before={ img( 1 ) } after={ null } /> );
		expect( view.container.innerHTML ).toBe( '' );
		view.unmount();
	} );
} );

describe( 'ResultCard', () => {
	it( 'approves with the chosen mode', async () => {
		const onChange = jest.fn();
		apiFetch.mockResolvedValueOnce( pendingResult( { status: 'approved', apply_mode: 'add' } ) );
		const view = await render( wrap( <ResultCard result={ pendingResult() } onChange={ onChange } /> ) );
		await change( view.$( '.dev3is-rcard__actions select' ), 'add' );
		await click( view.getByText( 'Approve' ) );
		await view.flush();
		const call = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( call.url ).toBe( '/wp-json/dev3is/v1/results/7/approve' );
		expect( call.data ).toEqual( { mode: 'add' } );
		expect( onChange ).toHaveBeenCalledWith( expect.objectContaining( { status: 'approved' } ) );
		view.unmount();
	} );

	it( 'offers revert for approved replacements and only library mode without a product', async () => {
		const view = await render( wrap( <ResultCard result={ pendingResult( { status: 'approved' } ) } onChange={ () => {} } /> ) );
		expect( view.queryByText( 'Revert' ) ).not.toBeNull();
		view.unmount();
		const v2 = await render( wrap( <ResultCard result={ pendingResult( { product_id: 0 } ) } onChange={ () => {} } /> ) );
		expect( v2.$$( '.dev3is-rcard__actions option' ).map( ( o ) => o.value ) ).toEqual( [ 'library' ] );
		v2.unmount();
	} );

	it( 'reviews alt text drafts', async () => {
		apiFetch.mockResolvedValueOnce( pendingResult( { kind: 'alt', status: 'approved', alt: { alt: 'Blue mug' } } ) );
		const onChange = jest.fn();
		const view = await render( wrap( <ResultCard result={ pendingResult( { kind: 'alt', operation: 'alt', result_id: 0, after: null, alt: { alt: 'Blue ceramic mug on white', title: 'Blue mug', caption: '' } } ) } onChange={ onChange } /> ) );
		expect( view.queryByText( 'Not saved yet' ) ).not.toBeNull();
		await click( view.getByText( 'Save alt text' ) );
		await view.flush();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe( '/wp-json/dev3is/v1/results/7/approve' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.alt ).toBe( 'Blue ceramic mug on white' );
		view.unmount();
	} );
} );

describe( 'Studio', () => {
	const detail = { key: '11:3:featured', attachment: img( 11 ), product_id: 3, product_name: 'Blue Mug', slot: 'featured', slot_label: 'Featured', edit_url: '/wp-admin/post.php?post=3', flags: { missing_alt: false, low_res: false, not_square: true, not_white: true, whiteness: 0.4 }, draft: false, history: [] };

	it( 'loads the image, runs a pack shot and shows the draft with a compare slider', async () => {
		apiFetch.mockImplementation( ( { url, data } ) => {
			if ( url.includes( 'images/11' ) ) {
				return Promise.resolve( detail );
			}
			if ( url.endsWith( '/process' ) ) {
				expect( data ).toEqual( expect.objectContaining( { attachment_id: 11, product_id: 3, slot: 'featured', operation: 'packshot' } ) );
				return Promise.resolve( { results: [ pendingResult() ] } );
			}
			return new Promise( () => {} );
		} );
		const reload = jest.fn();
		const view = await render( wrap( <Studio boot={ BOOT } params={ [ '11', '3', 'featured' ] } onUsage={ () => {} } reload={ reload } /> ) );
		await view.flush();
		expect( view.queryByText( 'Blue Mug' ) ).not.toBeNull();
		expect( view.queryByText( '40%' ) ).not.toBeNull();
		await click( view.getByText( 'Generate with DEV3 AI' ) );
		await view.flush();
		expect( view.$( '.dev3is-cmp__range' ) ).not.toBeNull();
		expect( view.queryByText( 'Waiting for review' ) ).not.toBeNull();
		expect( reload ).toHaveBeenCalled();
		view.unmount();
	} );

	it( 'keeps local tools usable when AI is not connected', async () => {
		apiFetch.mockImplementation( ( { url } ) => ( url.includes( 'images/11' ) ? Promise.resolve( detail ) : new Promise( () => {} ) ) );
		const boot = { ...BOOT, ai: { ...BOOT.ai, key_set: false } };
		const view = await render( wrap( <Studio boot={ boot } params={ [ '11', '3', 'featured' ] } onUsage={ () => {} } reload={ () => {} } /> ) );
		await view.flush();
		expect( view.getByText( 'Generate with DEV3 AI' ).closest( 'button' ).disabled ).toBe( true );
		expect( view.queryByText( 'DEV3 AI is not connected' ) ).not.toBeNull();
		await click( view.getByText( 'Square canvas' ).closest( 'button' ) );
		const create = view.getByText( 'Create square canvas' ).closest( 'button' );
		expect( create.disabled ).toBe( false );
		view.unmount();
	} );

	it( 'asks to pick an image without params', async () => {
		const view = await render( wrap( <Studio boot={ BOOT } params={ [] } onUsage={ () => {} } reload={ () => {} } /> ) );
		expect( view.queryByText( 'Pick an image to start' ) ).not.toBeNull();
		view.unmount();
	} );
} );

describe( 'Review queue', () => {
	it( 'bulk approves the selected results', async () => {
		apiFetch.mockImplementation( ( { url } ) => {
			if ( url.includes( 'results?' ) ) {
				return Promise.resolve( { rows: [ pendingResult(), pendingResult( { id: 8 } ) ], total: 2, counts: BOOT.counts } );
			}
			if ( url.endsWith( 'results/bulk' ) ) {
				return Promise.resolve( { done: 2, errors: [], counts: BOOT.counts } );
			}
			return new Promise( () => {} );
		} );
		const view = await render( wrap( <Review boot={ BOOT } reload={ () => {} } params={ [] } /> ) );
		await view.flush();
		expect( view.$$( '.dev3is-rcard' ) ).toHaveLength( 2 );
		await click( view.getByText( 'Select all on this page' ).closest( '.dev3is-ui-check' ).querySelector( 'input' ) );
		await click( view.getByText( 'Approve 2' ) );
		await view.flush();
		const bulk = apiFetch.mock.calls.find( ( c ) => c[ 0 ].url.endsWith( 'results/bulk' ) )[ 0 ];
		expect( bulk.data ).toEqual( { ids: [ 7, 8 ], action: 'approve', mode: 'replace' } );
		view.unmount();
	} );
} );

describe( 'Library and batch', () => {
	it( 'lists images, selects one and starts a batch from the selection', async () => {
		apiFetch.mockImplementation( ( { url } ) => {
			if ( url.includes( 'images?' ) ) {
				return Promise.resolve( { rows: [ { key: '11:3:featured', attachment: img( 11 ), product_id: 3, product_name: 'Blue Mug', sku: 'MUG-1', slot: 'featured', slot_label: 'Featured', flags: { missing_alt: true }, pending: 0 } ], total: 1, page: 1, per_page: 20 } );
			}
			return new Promise( () => {} );
		} );
		const navigate = jest.fn();
		const view = await render( wrap( <Library boot={ BOOT } navigate={ navigate } params={ [ 'missing_alt' ] } /> ) );
		await view.flush();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toContain( 'filter=missing_alt' );
		expect( view.queryByText( 'No alt text' ) ).not.toBeNull();
		await click( view.$( '.dev3is-selcell input' ) );
		await click( view.getByText( 'Batch 1 image' ) );
		expect( navigate ).toHaveBeenCalledWith( 'batch/selection' );
		expect( getSelection().targets ).toEqual( [ '11:3:featured' ] );
		view.unmount();
	} );

	it( 'estimates and starts a batch', async () => {
		setSelection( [ '11:3:featured', '12:4:featured' ] );
		apiFetch.mockImplementation( ( { url, data } ) => {
			if ( url.endsWith( '/batches' ) ) {
				return Promise.resolve( [] );
			}
			if ( url.endsWith( '/batch' ) && data.dry_run ) {
				return Promise.resolve( { count: 2, ai_calls: 2 } );
			}
			if ( url.endsWith( '/batch' ) ) {
				return Promise.resolve( { id: 9, status: 'queued', total: 2, done: 0, failed: 0, processed: 0, percent: 0, errors: [] } );
			}
			return new Promise( () => {} );
		} );
		const view = await render( wrap( <BatchScreen boot={ BOOT } params={ [ 'selection' ] } navigate={ () => {} } reload={ () => {} } onUsage={ () => {} } /> ) );
		await act( async () => new Promise( ( r ) => setTimeout( r, 400 ) ) );
		await view.flush();
		expect( view.queryByText( '2 images' ) ).not.toBeNull();
		await click( view.getByText( 'Start batch with DEV3 AI' ) );
		await view.flush();
		const start = apiFetch.mock.calls.filter( ( c ) => c[ 0 ].url.endsWith( '/batch' ) && ! c[ 0 ].data.dry_run )[ 0 ][ 0 ];
		expect( start.data.targets ).toEqual( [ '11:3:featured', '12:4:featured' ] );
		expect( start.data.operation ).toBe( 'packshot' );
		expect( view.queryByText( 'Review results' ) ).not.toBeNull();
		view.unmount();
	} );

	it( 'shows dashboard KPIs and the connect notice', async () => {
		const view = await render( wrap( <Dashboard boot={ BOOT } apiKeySet={ false } /> ) );
		expect( view.queryByText( 'Connect DEV3 AI' ) ).not.toBeNull();
		expect( view.queryByText( 'Missing alt text' ) ).not.toBeNull();
		view.unmount();
	} );
} );

describe( 'storefront compare', () => {
	it( 'links the range input to the position variable', () => {
		document.body.innerHTML = '<figure class="dev3is-compare" style="--dev3is-pos:50%"><div class="dev3is-compare__frame"><input class="dev3is-compare__range" type="range" value="50"></div></figure>';
		initCompare();
		const range = document.querySelector( 'input' );
		range.value = '30';
		range.dispatchEvent( new window.Event( 'input' ) );
		expect( document.querySelector( 'figure' ).style.getPropertyValue( '--dev3is-pos' ) ).toBe( '30%' );
		initCompare(); // idempotent
		expect( document.querySelector( 'figure' ).dataset.dev3isReady ).toBe( '1' );
	} );
} );
