import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, IconButton, Modal, PageHeader, ResponsiveTable, Select, Stack, TextField, Textarea, useToast } from '../core3/ui';
import { OPS, OperationOptions, defaultOptions, opLabel } from './ops';

const summary = ( r ) => {
	const o = r.options || {};
	const parts = [];
	if ( o.background ) {
		parts.push( o.background );
	}
	if ( o.color && r.operation !== 'remove_bg' ) {
		parts.push( o.color );
	}
	if ( o.fill ) {
		parts.push( `${ o.fill }%` );
	}
	if ( o.size ) {
		parts.push( `${ o.size }px` );
	}
	if ( o.aspect ) {
		parts.push( o.aspect );
	}
	if ( o.preset ) {
		parts.push( o.preset );
	}
	if ( o.factor ) {
		parts.push( `${ o.factor }×` );
	}
	if ( o.format ) {
		parts.push( o.format );
	}
	return parts.join( ' · ' );
};

/**
 * Recipes (saved edit + options) and lifestyle scene presets.
 *
 * @param {Object}   props        Props.
 * @param {Object}   props.boot   Bootstrap.
 * @param {Function} props.reload Reload bootstrap.
 * @return {Element} Screen.
 */
export default function RecipesScreen( { boot, reload } ) {
	const toast = useToast();
	const [ recipes, setRecipes ] = useState( boot.recipes.recipes );
	const [ scenes, setScenes ] = useState( boot.recipes.scenes );
	const [ editing, setEditing ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ confirmReset, setConfirmReset ] = useState( false );

	const persist = ( data, message ) => {
		setBusy( true );
		return request( 'recipes', { method: 'POST', data } )
			.then( ( next ) => {
				setRecipes( next.recipes );
				setScenes( next.scenes );
				toast.success( message );
				reload();
				return next;
			} )
			.catch( ( e ) => {
				toast.error( e );
				throw e;
			} )
			.finally( () => setBusy( false ) );
	};

	const saveRecipe = () => {
		const list = editing.index === -1 ? [ ...recipes, editing.recipe ] : recipes.map( ( r, i ) => ( i === editing.index ? editing.recipe : r ) );
		persist( { recipes: list }, __( 'Recipe saved.', 'dev3-ai-product-image-studio-for-woocommerce' ) ).then( () => setEditing( null ) ).catch( () => {} );
	};
	const remove = ( index ) => persist( { recipes: recipes.filter( ( _, i ) => i !== index ) }, __( 'Recipe deleted.', 'dev3-ai-product-image-studio-for-woocommerce' ) ).catch( () => {} );
	const reset = () =>
		request( 'recipes/reset', { method: 'POST' } )
			.then( ( next ) => {
				setRecipes( next.recipes );
				setScenes( next.scenes );
				setConfirmReset( false );
				toast.success( __( 'Built-in recipes and scenes restored.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
				reload();
			} )
			.catch( ( e ) => toast.error( e ) );

	const setScene = ( i, key, value ) => setScenes( scenes.map( ( s, j ) => ( j === i ? { ...s, [ key ]: value } : s ) ) );
	const sceneDirty = JSON.stringify( scenes ) !== JSON.stringify( boot.recipes.scenes );

	return (
		<>
			<PageHeader
				title={ __( 'Recipes', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				description={ __( 'Save an edit with its options once, then use it from the studio or for whole batches.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				actions={
					<>
						<Button variant="ghost" icon="refresh" onClick={ () => setConfirmReset( true ) }>
							{ __( 'Restore built-ins', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
						<Button variant="primary" icon="plus" onClick={ () => setEditing( { index: -1, recipe: { id: '', name: '', operation: 'packshot', options: defaultOptions( 'packshot', boot.settings ) } } ) }>
							{ __( 'New recipe', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
					</>
				}
			/>
			<Card title={ __( 'Recipes', 'dev3-ai-product-image-studio-for-woocommerce' ) } flush>
				<ResponsiveTable
					caption={ __( 'Recipes', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					rows={ recipes.map( ( r, index ) => ( { ...r, index } ) ) }
					rowKey="id"
					onRowClick={ ( r ) => setEditing( { index: r.index, recipe: { ...r, options: { ...defaultOptions( r.operation, boot.settings ), ...r.options } } } ) }
					columns={ [
						{ key: 'name', label: __( 'Name', 'dev3-ai-product-image-studio-for-woocommerce' ), primary: true, render: ( r ) => <strong>{ r.name }</strong> },
						{
							key: 'operation',
							label: __( 'Edit', 'dev3-ai-product-image-studio-for-woocommerce' ),
							render: ( r ) => (
								<>
									{ opLabel( r.operation, boot.labels ) } { OPS.find( ( o ) => o.id === r.operation && o.ai ) ? <Badge tone="brand">AI</Badge> : null }
								</>
							),
						},
						{ key: 'options', label: __( 'Options', 'dev3-ai-product-image-studio-for-woocommerce' ), hideOnMobile: true, render: ( r ) => <span className="dev3is-muted">{ summary( r ) }</span> },
					] }
					rowActions={ ( r ) => (
						// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
						<span onClick={ ( e ) => e.stopPropagation() }>
							<IconButton icon="trash" label={ sprintf( /* translators: %s: recipe */ __( 'Delete %s', 'dev3-ai-product-image-studio-for-woocommerce' ), r.name ) } onClick={ () => remove( r.index ) } disabled={ busy } />
						</span>
					) }
					empty={ { icon: 'box', title: __( 'No recipes', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Create one, or restore the built-ins.', 'dev3-ai-product-image-studio-for-woocommerce' ) } }
				/>
			</Card>

			<Card
				title={ __( 'Lifestyle scene presets', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				description={ __( 'Settings that DEV3 AI can place your products in. Describe surface, surroundings and light.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" icon="plus" onClick={ () => setScenes( [ ...scenes, { id: '', label: '', prompt: '' } ] ) }>
							{ __( 'Add scene', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
						<Button variant="primary" loading={ busy } disabled={ ! sceneDirty || busy } onClick={ () => persist( { scenes }, __( 'Scenes saved.', 'dev3-ai-product-image-studio-for-woocommerce' ) ).catch( () => {} ) }>
							{ __( 'Save scenes', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
					</>
				}
			>
				<ul className="dev3is-scenes">
					{ scenes.map( ( s, i ) => (
						<li key={ i } className="dev3is-scene">
							<TextField label={ __( 'Name', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ s.label } onChange={ ( v ) => setScene( i, 'label', v ) } />
							<Textarea label={ __( 'Setting', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ s.prompt } onChange={ ( v ) => setScene( i, 'prompt', v ) } rows={ 2 } maxLength={ 500 } />
							<IconButton icon="trash" label={ sprintf( /* translators: %s: scene */ __( 'Remove %s', 'dev3-ai-product-image-studio-for-woocommerce' ), s.label || __( 'scene', 'dev3-ai-product-image-studio-for-woocommerce' ) ) } onClick={ () => setScenes( scenes.filter( ( _, j ) => j !== i ) ) } />
						</li>
					) ) }
				</ul>
			</Card>

			<Modal
				open={ !! editing }
				onClose={ () => setEditing( null ) }
				title={ editing && editing.index === -1 ? __( 'New recipe', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Edit recipe', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				size="lg"
				footer={
					<>
						<Button variant="ghost" onClick={ () => setEditing( null ) }>
							{ __( 'Cancel', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
						<Button variant="primary" loading={ busy } disabled={ ! editing || ! editing.recipe.name.trim() } onClick={ saveRecipe }>
							{ __( 'Save recipe', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
					</>
				}
			>
				{ editing && (
					<Stack gap={ 12 }>
						<TextField label={ __( 'Recipe name', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ editing.recipe.name } onChange={ ( v ) => setEditing( { ...editing, recipe: { ...editing.recipe, name: v } } ) } required data-autofocus />
						<Select
							label={ __( 'Edit', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							value={ editing.recipe.operation }
							onChange={ ( v ) => setEditing( { ...editing, recipe: { ...editing.recipe, operation: v, options: defaultOptions( v, boot.settings ) } } ) }
							options={ OPS.map( ( o ) => ( { value: o.id, label: o.ai ? `${ o.label } (DEV3 AI)` : o.label } ) ) }
						/>
						<OperationOptions op={ editing.recipe.operation } value={ editing.recipe.options } onChange={ ( o ) => setEditing( { ...editing, recipe: { ...editing.recipe, options: o } } ) } scenes={ scenes } webp={ boot.webp } />
					</Stack>
				) }
			</Modal>

			<Modal
				open={ confirmReset }
				onClose={ () => setConfirmReset( false ) }
				title={ __( 'Restore built-in recipes?', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				size="sm"
				footer={
					<>
						<Button variant="ghost" onClick={ () => setConfirmReset( false ) }>
							{ __( 'Cancel', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
						<Button variant="danger" onClick={ reset }>
							{ __( 'Restore', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						</Button>
					</>
				}
			>
				<p>{ __( 'Your custom recipes and scenes are replaced by the five built-in recipes and eight scenes.', 'dev3-ai-product-image-studio-for-woocommerce' ) }</p>
			</Modal>
		</>
	);
}
