import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Card, ColorField, FormGrid, NumberField, PageHeader, Select, Stack, Textarea, TextField, Toggle, useToast } from '../core3/ui';

export const KEYS = [ 'bg_color', 'output_format', 'jpeg_quality', 'fill_ratio', 'shadow', 'low_res', 'max_side', 'brand_style', 'alt_template', 'alt_language', 'hide_drafts', 'keep_rejected', 'batch_scope', 'default_mode' ];

/**
 * Studio settings (keys registered in includes/Settings.php).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.save     Save.
 * @param {Object}   props.boot     Bootstrap.
 * @return {Element} Settings.
 */
export default function SettingsTab( { settings, save, boot } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => form[ k ] !== settings[ k ] );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const values = {};
		KEYS.forEach( ( k ) => ( values[ k ] = form[ k ] === '' ? settings[ k ] : form[ k ] ) );
		save( values )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<form onSubmit={ submit }>
			<PageHeader title={ __( 'Settings', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'Defaults for new images, the review workflow and alt text.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
			<Stack gap={ 16 }>
				<Card title={ __( 'Output', 'dev3-ai-product-image-studio-for-woocommerce' ) } description={ __( 'How result images are saved. Results are always new files; originals are never touched.', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
					<FormGrid>
						<ColorField label={ __( 'Default background colour', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ form.bg_color } onChange={ set( 'bg_color' ) } />
						<Select
							label={ __( 'File format', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							value={ form.output_format }
							onChange={ set( 'output_format' ) }
							options={ [
								{ value: 'auto', label: __( 'Automatic (PNG when transparent, else JPEG)', 'dev3-ai-product-image-studio-for-woocommerce' ) },
								{ value: 'jpeg', label: 'JPEG' },
								{ value: 'png', label: 'PNG' },
								...( boot.webp ? [ { value: 'webp', label: 'WebP' } ] : [] ),
							] }
						/>
						<NumberField label={ __( 'JPEG / WebP quality', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 40 } max={ 100 } value={ form.jpeg_quality } onChange={ set( 'jpeg_quality' ) } />
						<NumberField label={ __( 'Pack shot fill (%)', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 40 } max={ 100 } value={ form.fill_ratio } onChange={ set( 'fill_ratio' ) } help={ __( 'How much of the square canvas the product covers.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
						<NumberField label={ __( 'Max image size (px)', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 512 } max={ 4096 } step={ 64 } value={ form.max_side } onChange={ set( 'max_side' ) } help={ __( 'Upper limit for upscales and pack shots.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
						<NumberField label={ __( 'Low-resolution threshold (px)', 'dev3-ai-product-image-studio-for-woocommerce' ) } min={ 100 } max={ 4000 } step={ 50 } value={ form.low_res } onChange={ set( 'low_res' ) } help={ __( 'Images whose shortest side is smaller are flagged.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
						<Toggle label={ __( 'Soft shadow on pack shots', 'dev3-ai-product-image-studio-for-woocommerce' ) } checked={ !! form.shadow } onChange={ set( 'shadow' ) } />
					</FormGrid>
				</Card>
				<Card title={ __( 'Review workflow', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
					<FormGrid>
						<Select
							label={ __( 'Default approve action', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							value={ form.default_mode }
							onChange={ set( 'default_mode' ) }
							options={ [
								{ value: 'replace', label: __( 'Replace the image in the product', 'dev3-ai-product-image-studio-for-woocommerce' ) },
								{ value: 'add', label: __( 'Add to the product gallery', 'dev3-ai-product-image-studio-for-woocommerce' ) },
								{ value: 'library', label: __( 'Keep in the media library only', 'dev3-ai-product-image-studio-for-woocommerce' ) },
							] }
							help={ __( 'Lifestyle scenes are always added to the gallery by default.', 'dev3-ai-product-image-studio-for-woocommerce' ) }
						/>
						<Select
							label={ __( 'Default batch images', 'dev3-ai-product-image-studio-for-woocommerce' ) }
							value={ form.batch_scope }
							onChange={ set( 'batch_scope' ) }
							options={ [
								{ value: 'featured', label: __( 'Featured image', 'dev3-ai-product-image-studio-for-woocommerce' ) },
								{ value: 'gallery', label: __( 'Gallery images', 'dev3-ai-product-image-studio-for-woocommerce' ) },
								{ value: 'variations', label: __( 'Variation images', 'dev3-ai-product-image-studio-for-woocommerce' ) },
								{ value: 'all', label: __( 'All images', 'dev3-ai-product-image-studio-for-woocommerce' ) },
							] }
						/>
						<Toggle label={ __( 'Hide unapproved results from the media library', 'dev3-ai-product-image-studio-for-woocommerce' ) } checked={ !! form.hide_drafts } onChange={ set( 'hide_drafts' ) } />
						<Toggle label={ __( 'Keep files of rejected results', 'dev3-ai-product-image-studio-for-woocommerce' ) } checked={ !! form.keep_rejected } onChange={ set( 'keep_rejected' ) } help={ __( 'Off: rejected drafts are deleted.', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
					</FormGrid>
				</Card>
				<Card title={ __( 'Scenes & alt text', 'dev3-ai-product-image-studio-for-woocommerce' ) }>
					<Stack gap={ 12 }>
						<Textarea label={ __( 'Brand style for lifestyle scenes', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ form.brand_style } onChange={ set( 'brand_style' ) } rows={ 2 } maxLength={ 400 } placeholder={ __( 'e.g. Scandinavian, soft natural light, muted earthy colours', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
						<FormGrid>
							<TextField label={ __( 'Alt text template (no AI)', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ form.alt_template } onChange={ set( 'alt_template' ) } help={ __( '{product} {category} {sku} {site} {n} {attribute:pa_color}', 'dev3-ai-product-image-studio-for-woocommerce' ) } />
							<TextField label={ __( 'Alt text language', 'dev3-ai-product-image-studio-for-woocommerce' ) } value={ form.alt_language } onChange={ set( 'alt_language' ) } help={ __( 'Empty = the site language.', 'dev3-ai-product-image-studio-for-woocommerce' ) } placeholder="English" />
						</FormGrid>
					</Stack>
				</Card>
				<div className="dev3is-savebar">
					<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty || busy }>
						{ __( 'Save settings', 'dev3-ai-product-image-studio-for-woocommerce' ) }
					</Button>
				</div>
			</Stack>
		</form>
	);
}
