import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import Dashboard from './Dashboard';
import Library from './Library';
import Studio from './Studio';
import Review from './Review';
import BatchScreen from './BatchScreen';
import RecipesScreen from './RecipesScreen';
import SettingsTab from './SettingsTab';

export const NAV = [
	{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'dashboard' },
	{ id: 'library', label: __( 'Product images', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'list' },
	{ id: 'studio', label: __( 'Studio', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'edit' },
	{ id: 'review', label: __( 'Review', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'inbox' },
	{ id: 'batch', label: __( 'Batch', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'bolt' },
	{ id: 'recipes', label: __( 'Recipes', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'box' },
	{ id: 'settings', label: __( 'Settings', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'settings', manage: true },
	{ id: 'api', label: __( 'API Config', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'key', manage: true },
	{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-product-image-studio-for-woocommerce' ), icon: 'chart', manage: true },
];

export default function App() {
	const nav = NAV.filter( ( n ) => ! n.manage || config.canManage );
	const [ route, navigate ] = useHashRoute( nav.map( ( n ) => n.id ), 'dashboard' );
	const [ boot, setBoot ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );

	const reload = useCallback( () => {
		setError( null );
		return request( 'bootstrap' )
			.then( setBoot )
			.catch( setError );
	}, [] );

	useEffect( () => {
		reload();
		if ( config.canManage ) {
			request( 'settings' ).then( setSettings ).catch( () => {} );
		}
	}, [ reload ] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			reload();
			return next;
		} );

	const onUsage = () => setMeter( ( n ) => n + 1 );
	const pending = boot ? boot.counts.pending : 0;
	const navWithBadges = nav.map( ( n ) => ( n.id === 'review' && pending ? { ...n, badge: String( pending ) } : n ) );
	const props = { boot, reload, navigate, onUsage, params: route.params };

	const screens = {
		dashboard: () => <Dashboard { ...props } apiKeySet={ boot.ai.key_set } />,
		library: () => <Library { ...props } />,
		studio: () => <Studio { ...props } />,
		review: () => <Review { ...props } />,
		batch: () => <BatchScreen { ...props } />,
		recipes: () => <RecipesScreen { ...props } />,
		settings: () => ( settings ? <SettingsTab settings={ settings } save={ save } boot={ boot } /> : <SkeletonCard lines={ 6 } /> ),
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Connect Google Gemini, pick the text and image models and set a monthly AI budget.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
				{ settings ? <ApiTab settings={ settings } save={ save } /> : <SkeletonCard lines={ 6 } /> }
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Product Image Studio for WooCommerce' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ navWithBadges }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key. Originals are never overwritten.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ reload } /> }
			{ ! boot && ! error && <SkeletonCard lines={ 5 } /> }
			{ boot && screens[ route.id ]() }
		</AppShell>
	);
}
