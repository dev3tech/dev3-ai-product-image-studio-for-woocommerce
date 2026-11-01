import { __, sprintf } from '@wordpress/i18n';
import { Badge, Button, Card, Columns, EmptyState, Notice, PageHeader, Stat, StatGrid, formatNumber } from '../core3/ui';
import { opLabel } from './ops';
import { ResultThumb } from './parts';

const QUICK = [
	{ filter: 'not_white', icon: 'sparkles', title: __( 'Fix backgrounds', 'dev3-ai-product-image-studio-for-woocommerce' ), text: __( 'Find photos that are not on white and turn them into clean pack shots.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ filter: 'low_res', icon: 'search', title: __( 'Upscale small photos', 'dev3-ai-product-image-studio-for-woocommerce' ), text: __( 'Images under your minimum size, ready for AI upscaling.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ filter: 'missing_alt', icon: 'edit', title: __( 'Write alt text', 'dev3-ai-product-image-studio-for-woocommerce' ), text: __( 'Accessible, SEO-friendly alt text from the photo itself.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ filter: 'not_square', icon: 'columns', title: __( 'Make them square', 'dev3-ai-product-image-studio-for-woocommerce' ), text: __( 'Even grids: centre and pad to 1:1 without AI.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

/**
 * Overview: image health KPIs, AI status, quick actions and recent results.
 *
 * @param {Object}  props           Props.
 * @param {Object}  props.boot      Bootstrap payload.
 * @param {boolean} props.apiKeySet Whether a key is set.
 * @return {Element} Screen.
 */
export default function Dashboard( { boot, apiKeySet } ) {
	const s = boot.stats;
	const pct = ( n ) => ( s.images ? Math.round( ( n / s.images ) * 100 ) : 0 );
	return (
		<>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				description={ __( 'The health of your product photos and what DEV3 AI has prepared for review.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				actions={
					<>
						<Button icon="bolt" href="#/batch">
							{ __( 'New batch', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
						<Button variant="primary" icon="list" href="#/library">
							{ __( 'Browse product images', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
					</>
				}
			/>

			{ ! apiKeySet && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-product-image-studio-for-woocommerce' ) } actions={ boot.canManage ? <Button size="sm" variant="primary" href="#/api">{ __( 'Add your API key', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Button> : null }>
					{ __( 'Add a Google Gemini API key to remove backgrounds, create pack shots and lifestyle scenes, upscale and write alt text. Square canvas, resizing, format conversion, alt templates and the review queue work without it.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				</Notice>
			) }
			{ ! boot.gd && (
				<Notice tone="error" title={ __( 'PHP GD is missing', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
					{ __( 'Ask your host to enable the PHP GD extension. The studio needs it to read and write images.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				</Notice>
			) }

			<StatGrid>
				<Stat label={ __( 'Product images', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ formatNumber( s.images ) } icon="box" tone="brand" hint={ sprintf( /* translators: %s: products */ __( 'across %s products', 'dev3-ai-product-image-studio-for-woocommerce' ), formatNumber( s.products ) ) } />
				<Stat label={ __( 'Waiting for review', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ formatNumber( boot.counts.pending ) } icon="inbox" tone={ boot.counts.pending ? 'warning' : 'neutral' } hint={ sprintf( /* translators: %s: approved */ __( '%s approved so far', 'dev3-ai-product-image-studio-for-woocommerce' ), formatNumber( boot.counts.approved ) ) } />
				<Stat label={ __( 'Missing alt text', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ formatNumber( s.missing_alt ) } icon="edit" tone={ s.missing_alt ? 'danger' : 'success' } hint={ sprintf( /* translators: %d: percent */ __( '%d%% of images', 'dev3-ai-product-image-studio-for-woocommerce' ), pct( s.missing_alt ) ) } />
				<Stat label={ __( 'Low resolution', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ formatNumber( s.low_res ) } icon="search" tone={ s.low_res ? 'warning' : 'success' } hint={ sprintf( /* translators: %d: px */ __( 'shortest side under %dpx', 'dev3-ai-product-image-studio-for-woocommerce' ), boot.settings.low_res ) } />
				<Stat
					label={ __( 'Not on white', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					value={ formatNumber( s.not_white ) }
					icon="sparkles"
					tone={ s.not_white ? 'warning' : 'success' }
					hint={ s.unaudited ? sprintf( /* translators: %s: count */ __( '%s not audited yet', 'dev3-ai-product-image-studio-for-woocommerce' ), formatNumber( s.unaudited ) ) : __( 'all images audited', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				/>
			</StatGrid>

			<Card title={ __( 'Quick fixes', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Open the library filtered to the images that need it, select them and run a batch.', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
				<Columns min={ 220 } gap={ 12 }>
					{ QUICK.map( ( q ) => (
						<a key={ q.filter } className="dev3is-quick" href={ `#/library/${ q.filter }` }>
							<span className="dev3is-quick__title">{ q.title }</span>
							<span className="dev3is-quick__text">{ q.text }</span>
							<span className="dev3is-quick__count">
								<Badge tone={ s[ q.filter ] ? 'warning' : 'success' }>{ s[ q.filter ] ? sprintf( /* translators: %s: count */ __( '%s images', 'dev3-ai-product-image-studio-for-woocommerce' ), formatNumber( s[ q.filter ] ) ) : __( 'All good', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Badge>
							</span>
						</a>
					) ) }
				</Columns>
			</Card>

			<Card
				title={ __( 'Latest results', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				description={ __( 'New images are saved as separate drafts. Your originals are never changed.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				actions={
					<Button size="sm" variant="ghost" href="#/review" iconRight="chevronRight">
						{ __( 'Open review queue', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					</Button>
				}
			>
				{ boot.recent.length ? (
					<div className="dev3is-thumbgrid">
						{ boot.recent.map( ( r ) => (
							<ResultThumb key={ r.id } result={ r } label={ opLabel( r.operation, boot.labels ) } />
						) ) }
					</div>
				) : (
					<EmptyState
						compact
						icon="sparkles"
						title={ __( 'No results yet', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						description={ __( 'Open a product image in the studio, or run a batch over many products.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						action={ <Button href="#/library">{ __( 'Pick an image', 'dev3-ai-product-image-studio-for-woocommerce' ) }</Button> }
					/>
				) }
			</Card>
		</>
	);
}
