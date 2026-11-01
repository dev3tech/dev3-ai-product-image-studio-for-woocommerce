import { __ } from '@wordpress/i18n';
import { ColorField, FormGrid, NumberField, Select, Stack, TextField, Textarea, Toggle } from '../core3/ui';

/** Image operations (order = studio order). */
export const OPS = [
	{ id: 'remove_bg', ai: true, label: __( 'Remove background', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'White, colour or transparent background.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'packshot', ai: true, label: __( 'White pack shot', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Studio pack shot, centred on a square canvas.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'scene', ai: true, label: __( 'Lifestyle scene', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Place the product in a photorealistic setting.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'upscale', ai: true, label: __( 'AI upscale', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Restore detail, then enlarge 2-4x.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'edit', ai: true, label: __( 'Custom edit', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Describe any change in plain words.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'square', ai: false, label: __( 'Square canvas', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Trim, centre and pad. No AI.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'local_upscale', ai: false, label: __( 'Resize up', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Enlarge with sharpening. No AI.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'flatten', ai: false, label: __( 'Flatten', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Fill transparency with a colour. No AI.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'convert', ai: false, label: __( 'Convert format', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'PNG, JPEG or WebP copy. No AI.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

/** Extra batch-only operations. */
export const BATCH_EXTRA = [
	{ id: 'alt', ai: true, label: __( 'Alt text with DEV3 AI', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Vision alt text, title and caption drafts.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'alt_template', ai: false, label: __( 'Alt text from a template', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Fill alt text from product data. No AI.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
	{ id: 'audit', ai: false, label: __( 'Audit images', 'dev3-ai-product-image-studio-for-woocommerce' ), description: __( 'Measure background whiteness for the filters.', 'dev3-ai-product-image-studio-for-woocommerce' ) },
];

export const ALL_OPS = [ ...OPS, ...BATCH_EXTRA ];

export const opLabel = ( id, labels = {} ) => {
	const op = ALL_OPS.find( ( o ) => o.id === id );
	return labels[ id ] || ( op ? op.label : id );
};

export const isAi = ( id ) => !! ( ALL_OPS.find( ( o ) => o.id === id ) || {} ).ai;

export const ASPECTS = [ '1:1', '4:5', '3:4', '4:3', '16:9', '9:16' ].map( ( a ) => ( { value: a, label: a } ) );

/**
 * Default options for an operation.
 *
 * @param {string} op       Operation.
 * @param {Object} settings Studio settings.
 * @return {Object} Options.
 */
export function defaultOptions( op, settings = {} ) {
	const color = settings.bg_color || '#ffffff';
	const fill = settings.fill_ratio || 85;
	switch ( op ) {
		case 'remove_bg':
			return { background: 'white', color };
		case 'packshot':
			return { color, fill, size: 0, shadow: settings.shadow !== false };
		case 'scene':
			return { preset: 'kitchen', prompt: '', aspect: '1:1', variants: 1 };
		case 'upscale':
		case 'local_upscale':
			return { factor: 2, long: 0 };
		case 'edit':
			return { instruction: '' };
		case 'square':
			return { color, fill, size: 0, transparent: false };
		case 'flatten':
			return { color };
		case 'convert':
			return { format: 'webp', quality: settings.jpeg_quality || 90 };
		case 'alt_template':
			return { template: settings.alt_template || '{product} - {category}' };
	}
	return {};
}

/**
 * Whether the options are complete enough to run.
 *
 * @param {string} op Operation.
 * @param {Object} o  Options.
 * @return {boolean} Ready.
 */
export function optionsReady( op, o = {} ) {
	if ( op === 'edit' ) {
		return !! ( o.instruction || '' ).trim();
	}
	if ( op === 'scene' ) {
		return !! ( o.preset || ( o.prompt || '' ).trim() );
	}
	if ( op === 'alt_template' ) {
		return !! ( o.template || '' ).trim();
	}
	return true;
}

/**
 * Option fields for one operation.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.op       Operation.
 * @param {Object}   props.value    Options.
 * @param {Function} props.onChange ( next ) => void.
 * @param {Array}    props.scenes   Scene presets.
 * @param {boolean}  props.webp     WebP supported.
 * @return {Element} Fields.
 */
export function OperationOptions( { op, value = {}, onChange, scenes = [], webp = true } ) {
	const set = ( key ) => ( v ) => onChange( { ...value, [ key ]: v } );
	const color = <ColorField label={ __( 'Background colour', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ value.color || '#ffffff' } onChange={ set( 'color' ) } />;
	const fill = <NumberField label={ __( 'Product fill (%)', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 40 } max={ 100 } value={ value.fill } onChange={ set( 'fill' ) } help={ __( 'How much of the canvas the product covers.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />;
	const size = <NumberField label={ __( 'Canvas size (px)', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 0 } max={ 4096 } step={ 50 } value={ value.size } onChange={ set( 'size' ) } help={ __( '0 = automatic.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />;
	switch ( op ) {
		case 'remove_bg':
			return (
				<FormGrid>
					<Select
						label={ __( 'New background', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						value={ value.background || 'white' }
						onChange={ set( 'background' ) }
						options={ [
							{ value: 'white', label: __( 'Pure white', 'dev3-ai-product-image-studio-for-woocommerce' ) },
							{ value: 'color', label: __( 'Solid colour', 'dev3-ai-product-image-studio-for-woocommerce' ) },
							{ value: 'transparent', label: __( 'Transparent (PNG)', 'dev3-ai-product-image-studio-for-woocommerce' ) },
						] }
						help={ value.background === 'transparent' ? __( 'DEV3 AI paints a key colour behind the product, then the plugin cuts it out locally.', 'dev3-ai-product-image-studio-for-woocommerce' ) : undefined }
					/>
					{ value.background === 'color' && color }
				</FormGrid>
			);
		case 'packshot':
			return (
				<FormGrid>
					{ color }
					{ fill }
					{ size }
					<Toggle label={ __( 'Soft contact shadow', 'dev3-ai-product-image-studio-for-woocommerce' ) } checked={ !! value.shadow } onChange={ set( 'shadow' ) } />
				</FormGrid>
			);
		case 'scene':
			return (
				<Stack gap={ 12 }>
					<FormGrid>
						<Select
							label={ __( 'Scene', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							value={ value.preset || '' }
							onChange={ set( 'preset' ) }
							options={ [ ...scenes.map( ( s ) => ( { value: s.id, label: s.label } ) ), { value: '', label: __( 'Custom description…', 'dev3-ai-product-image-studio-for-woocommerce' ) } ] }
						/>
						<Select label={ __( 'Aspect ratio', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ value.aspect || '1:1' } onChange={ set( 'aspect' ) } options={ ASPECTS } />
						<NumberField label={ __( 'Variants', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 1 } max={ 4 } value={ value.variants } onChange={ set( 'variants' ) } help={ __( 'One AI image per variant.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
					</FormGrid>
					<Textarea
						label={ value.preset ? __( 'Custom description (optional, replaces the preset)', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Describe the setting', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						value={ value.prompt || '' }
						onChange={ set( 'prompt' ) }
						rows={ 2 }
						maxLength={ 500 }
						placeholder={ __( 'e.g. on a sunny café table with a croissant and a latte', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					/>
				</Stack>
			);
		case 'upscale':
		case 'local_upscale':
			return (
				<FormGrid>
					<Select
						label={ __( 'Enlarge by', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						value={ String( value.factor || 2 ) }
						onChange={ ( v ) => onChange( { ...value, factor: Number( v ) } ) }
						options={ [
							{ value: '2', label: '2×' },
							{ value: '3', label: '3×' },
							{ value: '4', label: '4×' },
						] }
					/>
					<NumberField label={ __( 'Or long edge (px)', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 0 } max={ 4096 } step={ 100 } value={ value.long } onChange={ set( 'long' ) } help={ __( '0 = use the factor. Capped by the max size in Settings.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
				</FormGrid>
			);
		case 'edit':
			return (
				<Textarea
					label={ __( 'What should change?', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					value={ value.instruction || '' }
					onChange={ set( 'instruction' ) }
					rows={ 3 }
					maxLength={ 500 }
					placeholder={ __( 'e.g. Remove the price sticker and straighten the label', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				/>
			);
		case 'square':
			return (
				<FormGrid>
					{ ! value.transparent && color }
					{ fill }
					{ size }
					<Toggle label={ __( 'Transparent canvas', 'dev3-ai-product-image-studio-for-woocommerce' ) } checked={ !! value.transparent } onChange={ set( 'transparent' ) } />
				</FormGrid>
			);
		case 'flatten':
			return <FormGrid>{ color }</FormGrid>;
		case 'convert':
			return (
				<FormGrid>
					<Select
						label={ __( 'Format', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						value={ value.format || 'webp' }
						onChange={ set( 'format' ) }
						options={ [ ...( webp ? [ { value: 'webp', label: 'WebP' } ] : [] ), { value: 'jpeg', label: 'JPEG' }, { value: 'png', label: 'PNG' } ] }
					/>
					<NumberField label={ __( 'Quality', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 40 } max={ 100 } value={ value.quality } onChange={ set( 'quality' ) } />
				</FormGrid>
			);
		case 'alt_template':
			return (
				<TextField
					label={ __( 'Alt text template', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					value={ value.template || '' }
					onChange={ set( 'template' ) }
					help={ __( 'Placeholders: {product} {category} {sku} {site} {n} {attribute:pa_color}', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				/>
			);
	}
	return null;
}
