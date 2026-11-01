import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, MediaUpload, MediaUploadCheck, useBlockProps } from '@wordpress/block-editor';
import { Button, PanelBody, RangeControl, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

function Picker( { label, id, onSelect } ) {
	return (
		<MediaUploadCheck>
			<MediaUpload
				allowedTypes={ [ 'image' ] }
				value={ id }
				onSelect={ ( media ) => onSelect( media.id ) }
				render={ ( { open } ) => (
					<Button variant={ id ? 'secondary' : 'primary' } onClick={ open } style={ { marginRight: 8, marginBottom: 8 } }>
						{ label }
					</Button>
				) }
			/>
		</MediaUploadCheck>
	);
}

registerBlockType( 'dev3is/compare', {
	apiVersion: 2,
	title: __( 'Before & After Image', 'dev3-ai-product-image-studio-for-woocommerce' ),
	description: __( 'A draggable before/after comparison of two images.', 'dev3-ai-product-image-studio-for-woocommerce' ),
	category: 'media',
	icon: 'image-flip-horizontal',
	keywords: [ __( 'compare', 'dev3-ai-product-image-studio-for-woocommerce' ), __( 'slider', 'dev3-ai-product-image-studio-for-woocommerce' ), __( 'before after', 'dev3-ai-product-image-studio-for-woocommerce' ) ],
	supports: { align: [ 'wide', 'full', 'center' ] },
	attributes: {
		before: { type: 'number', default: 0 },
		after: { type: 'number', default: 0 },
		position: { type: 'number', default: 50 },
		beforeLabel: { type: 'string', default: '' },
		afterLabel: { type: 'string', default: '' },
		caption: { type: 'string', default: '' },
	},
	edit( { attributes, setAttributes } ) {
		const props = useBlockProps();
		const pickers = (
			<>
				<Picker label={ attributes.before ? __( 'Replace "before" image', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Choose "before" image', 'dev3-ai-product-image-studio-for-woocommerce' ) } id={ attributes.before } onSelect={ ( before ) => setAttributes( { before } ) } />
				<Picker label={ attributes.after ? __( 'Replace "after" image', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Choose "after" image', 'dev3-ai-product-image-studio-for-woocommerce' ) } id={ attributes.after } onSelect={ ( after ) => setAttributes( { after } ) } />
			</>
		);
		return (
			<div { ...props }>
				<InspectorControls>
					<PanelBody title={ __( 'Images', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
						{ pickers }
						<RangeControl label={ __( 'Start position (%)', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ attributes.position } min={ 0 } max={ 100 } onChange={ ( position ) => setAttributes( { position } ) } />
						<TextControl label={ __( '"Before" label', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ attributes.beforeLabel } onChange={ ( beforeLabel ) => setAttributes( { beforeLabel } ) } placeholder={ __( 'Before', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
						<TextControl label={ __( '"After" label', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ attributes.afterLabel } onChange={ ( afterLabel ) => setAttributes( { afterLabel } ) } placeholder={ __( 'After', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
						<TextControl label={ __( 'Caption', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ attributes.caption } onChange={ ( caption ) => setAttributes( { caption } ) } />
					</PanelBody>
				</InspectorControls>
				{ attributes.before && attributes.after ? (
					<ServerSideRender block="dev3is/compare" attributes={ attributes } />
				) : (
					<div style={ { padding: 24, border: '1px dashed #949494', borderRadius: 8, textAlign: 'center' } }>
						<p style={ { marginTop: 0 } }>{ __( 'Pick the "before" and "after" images. Tip: approved DEV3 AI Image Studio results sit next to their originals in the media library.', 'dev3-ai-product-image-studio-for-woocommerce' ) }</p>
						{ pickers }
					</div>
				) }
			</div>
		);
	},
	save: () => null,
} );
