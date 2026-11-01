import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { cx } from '../core3/ui';

/**
 * Before/after compare slider. A native range input sits over the frame, so mouse drag, touch and
 * keyboard (arrows, Home/End, Page Up/Down) all work without custom pointer code.
 *
 * @param {Object}  props             Props.
 * @param {Object}  props.before      { url, width, height, alt }.
 * @param {Object}  props.after       { url, width, height, alt }.
 * @param {number}  props.initial     Start position (0-100).
 * @param {string}  props.beforeLabel Label.
 * @param {string}  props.afterLabel  Label.
 * @param {boolean} props.checker     Checkerboard behind transparent images.
 * @param {string}  props.className   Extra classes.
 * @return {Element} Slider.
 */
export default function CompareSlider( { before, after, initial = 50, beforeLabel, afterLabel, checker = true, className } ) {
	const [ pos, setPos ] = useState( initial );
	const [ mode, setMode ] = useState( 'slider' );
	const bLabel = beforeLabel || __( 'Before', 'dev3-ai-product-image-studio-for-woocommerce' );
	const aLabel = afterLabel || __( 'After', 'dev3-ai-product-image-studio-for-woocommerce' );
	if ( ! before || ! after ) {
		return null;
	}
	const ratio = after.width && after.height ? after.width / after.height : 1;
	return (
		<div className={ cx( 'dev3is-cmp', checker && 'is-checker', className ) }>
			{ mode === 'slider' ? (
				<div className="dev3is-cmp__frame" style={ { '--dev3is-pos': `${ pos }%`, aspectRatio: String( ratio ) } }>
					<img className="dev3is-cmp__img" src={ after.large || after.url } alt={ aLabel } draggable="false" />
					<div className="dev3is-cmp__before" aria-hidden="true">
						<img className="dev3is-cmp__img" src={ before.large || before.url } alt="" draggable="false" />
					</div>
					<span className="dev3is-cmp__tag is-before" aria-hidden="true">{ bLabel }</span>
					<span className="dev3is-cmp__tag is-after" aria-hidden="true">{ aLabel }</span>
					<span className="dev3is-cmp__handle" aria-hidden="true">
						<span className="dev3is-cmp__knob" />
					</span>
					<input
						className="dev3is-cmp__range"
						type="range"
						min="0"
						max="100"
						step="1"
						value={ pos }
						onChange={ ( e ) => setPos( Number( e.target.value ) ) }
						/* translators: 1: before label, 2: after label */
						aria-label={ sprintf( __( 'Compare %1$s and %2$s', 'dev3-ai-product-image-studio-for-woocommerce' ), bLabel, aLabel ) }
						aria-valuetext={ sprintf( /* translators: %d: percent of the before image shown */ __( '%d%% before', 'dev3-ai-product-image-studio-for-woocommerce' ), pos ) }
					/>
				</div>
			) : (
				<div className="dev3is-cmp__side">
					<figure>
						<img src={ before.large || before.url } alt={ bLabel } />
						<figcaption>{ bLabel }</figcaption>
					</figure>
					<figure>
						<img src={ after.large || after.url } alt={ aLabel } />
						<figcaption>{ aLabel }</figcaption>
					</figure>
				</div>
			) }
			<div className="dev3is-cmp__bar">
				<span className="dev3is-cmp__dims">
					{ before.width }×{ before.height } → <strong>{ after.width }×{ after.height }</strong>
				</span>
				<button type="button" className="dev3is-cmp__mode" aria-pressed={ mode === 'side' } onClick={ () => setMode( mode === 'slider' ? 'side' : 'slider' ) }>
					{ mode === 'slider' ? __( 'Side by side', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Slider', 'dev3-ai-product-image-studio-for-woocommerce' ) }
				</button>
			</div>
		</div>
	);
}
