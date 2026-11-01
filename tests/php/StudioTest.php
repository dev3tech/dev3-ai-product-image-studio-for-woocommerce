<?php
/**
 * Image tools, audit, library, operations (AI mocked), approval workflow.
 *
 * @package Dev3\AiImageStudio
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiImageStudio\Core3\Core\Settings_Store;
use Dev3\AiImageStudio\Core3\Data\Usage_Repository;
use Dev3\AiImageStudio\Studio\Audit;
use Dev3\AiImageStudio\Studio\Image_Tools;
use Dev3\AiImageStudio\Studio\Library;
use Dev3\AiImageStudio\Studio\Operations;
use Dev3\AiImageStudio\Studio\Results;

class StudioTest extends Dev3is_Base_Case {

	public function test_chroma_key_makes_the_backdrop_transparent() {
		$img = Image_Tools::canvas( 120, 80, array( 0, 255, 0 ) );
		imagefilledrectangle( $img, 40, 20, 79, 59, imagecolorallocate( $img, 200, 30, 30 ) );
		$out = Image_Tools::chroma_key( $img );
		$this->assertSame( 127, Image_Tools::pixel( $out, 2, 2 )[3], 'Backdrop is fully transparent' );
		$this->assertSame( array( 200, 30, 30, 0 ), Image_Tools::pixel( $out, 60, 40 ), 'Product stays opaque and unchanged' );
		$this->assertTrue( Image_Tools::has_alpha( $out ) );
		$this->assertSame( 'image/png', Image_Tools::pick_mime( 'auto', $out ) );
		$this->assertSame( 'image/png', Image_Tools::pick_mime( 'jpeg', $out ), 'JPEG cannot hold alpha' );
	}

	public function test_square_canvas_respects_the_fill_ratio() {
		$img = Image_Tools::canvas( 400, 300, array( 255, 255, 255 ) );
		imagefilledrectangle( $img, 100, 100, 199, 149, imagecolorallocate( $img, 0, 0, 0 ) );
		$sq  = Image_Tools::square( $img, 1000, 80, array( 255, 255, 255 ) );
		$this->assertSame( 1000, imagesx( $sq ) );
		$this->assertSame( 1000, imagesy( $sq ) );
		$box = Image_Tools::content_box( $sq, array( 255, 255, 255 ) );
		$this->assertEqualsWithDelta( 800, $box['w'], 40, 'Longest side fills ~80%' );
		$this->assertEqualsWithDelta( 500, $box['x'] + $box['w'] / 2, 20, 'Centred horizontally' );
		$this->assertEqualsWithDelta( 500, $box['y'] + $box['h'] / 2, 20, 'Centred vertically' );
		$this->assertSame( 1.0, Image_Tools::border_whiteness( $sq ) );
	}

	public function test_upscale_size_is_capped() {
		$this->assertSame( array( 1600, 1200 ), Image_Tools::upscale_size( 800, 600, 2, 0, 4096 ) );
		$this->assertSame( array( 2048, 1536 ), Image_Tools::upscale_size( 800, 600, 4, 0, 2048 ) );
		$this->assertSame( array( 3000, 2250 ), Image_Tools::upscale_size( 800, 600, 2, 3000, 4096 ) );
		$this->assertSame( array( 800, 600 ), Image_Tools::upscale_size( 800, 600, 2, 0, 600 ), 'Never shrinks' );
		$this->assertSame( array( 255, 0, 170 ), Image_Tools::rgb( '#f0a' ) );
	}

	public function test_audit_flags_and_cache() {
		$grey  = $this->attachment( $this->png( 400, 300 ) );
		$white = $this->attachment( $this->png( 900, 900, array( 255, 255, 255 ) ) );
		update_post_meta( $white, '_wp_attachment_image_alt', 'A mug' );
		$f = Audit::flags( $grey );
		$this->assertTrue( $f['missing_alt'] );
		$this->assertTrue( $f['low_res'] );
		$this->assertTrue( $f['not_square'] );
		$this->assertTrue( $f['not_white'] );
		$this->assertSame( 0, $f['score'] );
		$w = Audit::flags( $white );
		$this->assertFalse( $w['missing_alt'] || $w['low_res'] || $w['not_square'] || $w['not_white'] );
		$this->assertSame( 100, $w['score'] );
		$this->assertIsArray( get_post_meta( $white, Audit::META, true ), 'Whiteness is cached' );
		$this->assertNull( Audit::flags( $this->attachment( $this->png() ), false )['not_white'], 'Unknown until audited' );
	}

	public function test_library_lists_featured_gallery_and_variation_images() {
		$a = $this->product( 'Blue Mug' );
		$this->product( 'Red Plate' );
		$var = new WC_Product_Variable();
		$var->set_name( 'Tee' );
		$var->save();
		$v = new WC_Product_Variation();
		$v->set_parent_id( $var->get_id() );
		$vimg = $this->attachment( $this->png(), $var->get_id() );
		$v->set_image_id( $vimg );
		$v->save();

		$all = Library::targets();
		$this->assertCount( 7, $all );
		$this->assertContains( 'variation:' . $v->get_id(), wp_list_pluck( $all, 'slot' ) );
		$this->assertCount( 1, Library::targets( array( $a['product']->get_id() ), 'featured' ) );
		$this->assertCount( 2, Library::targets( array( $a['product']->get_id() ), 'gallery' ) );
		$this->assertSame( $var->get_id(), Library::targets( array( $var->get_id() ), 'variations' )[0]['product_id'] );

		$res = Library::query( array( 'search' => 'Blue' ) );
		$this->assertSame( 3, $res['total'] );
		$this->assertSame( 'Blue Mug', $res['rows'][0]['product_name'] );
		$this->assertSame( 'featured', $res['rows'][0]['slot'] );
		$this->assertSame( 3, Library::query( array( 'search' => $a['product']->get_sku() ) )['total'], 'SKU search' );
		$this->assertSame( 5, Library::query( array( 'filter' => 'low_res' ) )['total'] );
		$this->assertSame( 3, Library::query( array( 'filter' => 'not_white' ) )['total'] );
		$stats = Library::stats();
		$this->assertSame( 7, $stats['images'] );
		$this->assertSame( 3, $stats['products'] );
	}

	public function test_local_square_creates_a_hidden_draft_and_never_touches_the_original() {
		$p        = $this->product();
		$file     = get_attached_file( $p['featured'] );
		$hash     = md5_file( $file );
		$rows     = Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'square', array( 'fill' => 90 ) );
		$this->assertIsArray( $rows );
		$r = $rows[0];
		$this->assertSame( 'pending', $r['status'] );
		$this->assertSame( 'square', $r['operation'] );
		$this->assertNotSame( $p['featured'], $r['result_id'] );
		$this->assertSame( $r['after']['width'], $r['after']['height'] );
		$this->assertSame( '1', get_post_meta( $r['result_id'], Results::DRAFT, true ) );
		$this->assertSame( $hash, md5_file( $file ), 'Original file unchanged' );
		$this->assertSame( $p['featured'], (int) wc_get_product( $p['product']->get_id() )->get_image_id(), 'Product unchanged until approval' );
		$this->assertStringContainsString( 'blue-mug-square', basename( get_attached_file( $r['result_id'] ) ), 'SEO file name' );
		$q = Results::hide_drafts_ajax( array() );
		$this->assertSame( Results::DRAFT, $q['meta_query'][0]['key'] );
		$ids = get_posts(
			array_merge(
				$q,
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			)
		);
		$this->assertNotContains( $r['result_id'], $ids, 'Drafts are hidden from the media library' );
		$this->assertContains( $p['featured'], $ids );
	}

	public function test_ai_packshot_sends_the_photo_and_finishes_locally() {
		$p               = $this->product();
		$this->responses = array( $this->image_reply( $this->png( 600, 400, array( 255, 255, 255 ) ) ) );
		$rows            = Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'packshot', array( 'size' => 800, 'fill' => 80 ) );
		$this->assertIsArray( $rows, is_wp_error( $rows ) ? $rows->get_error_message() : '' );
		$this->assertCount( 1, $this->requests );
		$body  = $this->requests[0]['body'];
		$parts = $body['contents'][0]['parts'];
		$this->assertArrayHasKey( 'inline_data', $parts[0], 'Original photo is sent as input' );
		$this->assertStringContainsString( 'pack shot', $parts[1]['text'] );
		$this->assertStringContainsString( 'Blue Mug', $parts[1]['text'] );
		$this->assertStringContainsString( 'gemini-2.5-flash-image', $this->requests[0]['url'] );
		$this->assertSame( 800, $rows[0]['after']['width'] );
		$this->assertSame( 800, $rows[0]['after']['height'] );
		$this->assertSame( 1590, $rows[0]['meta']['tokens'] );
		$this->assertSame( 1, Usage_Repository::month()['requests'] );
	}

	public function test_transparent_background_returns_a_png_with_alpha() {
		$p               = $this->product();
		$this->responses = array( $this->image_reply( $this->png( 300, 300, array( 0, 255, 0 ), array( 200, 40, 40 ) ) ) );
		$rows            = Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'remove_bg', array( 'background' => 'transparent' ) );
		$this->assertStringContainsString( '#00FF00', $this->requests[0]['body']['contents'][0]['parts'][1]['text'] );
		$this->assertSame( 'image/png', $rows[0]['after']['mime'] );
		$this->assertSame( 'Transparent background', $rows[0]['meta']['label'] );
		$img = Image_Tools::from_file( get_attached_file( $rows[0]['result_id'] ) );
		$this->assertSame( 127, Image_Tools::pixel( $img, 3, 3 )[3] );
		$this->assertSame( 0, Image_Tools::pixel( $img, 150, 150 )[3] );
	}

	public function test_scene_variants_upscale_and_errors() {
		$p               = $this->product();
		$this->responses = array( $this->image_reply( $this->png( 400, 500 ) ) );
		$rows            = Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'scene', array( 'preset' => 'marble', 'aspect' => '4:5', 'variants' => 2 ) );
		$this->assertCount( 2, $rows );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( '4:5', $this->requests[0]['body']['generationConfig']['imageConfig']['aspectRatio'] );
		$this->assertStringContainsString( 'Carrara marble', $this->requests[0]['body']['contents'][0]['parts'][1]['text'] );
		$this->assertSame( 'add', $rows[0]['apply_mode'], 'Scenes join the gallery by default' );
		$this->assertSame( 2, $rows[1]['meta']['variant'] );

		$this->responses = array( $this->image_reply( $this->png( 200, 150 ) ) );
		$up              = Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'upscale', array( 'factor' => 2 ) );
		$this->assertSame( 800, $up[0]['after']['width'], 'Resampled to 2x the original 400px' );

		$this->responses = array( array( 200, array( 'candidates' => array( array( 'finishReason' => 'IMAGE_SAFETY', 'content' => array( 'parts' => array() ) ) ) ) ) );
		$blocked         = Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'edit', array( 'instruction' => 'x' ) );
		$this->assertWPError( $blocked );
		$this->assertSame( 'dev3is_blocked', $blocked->get_error_code() );
		$this->assertWPError( Operations::run( $p['featured'], 0, '', 'edit', array() ), 'Edit needs an instruction' );
		$this->assertWPError( Operations::run( $p['featured'], 0, '', 'nope' ) );
	}

	public function test_ai_off_blocks_ai_but_local_tools_work() {
		Settings_Store::update( array( 'ai_enabled' => false ) );
		$p   = $this->product();
		$res = Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'packshot' );
		$this->assertWPError( $res );
		$this->assertSame( 'dev3is_ai_disabled', $res->get_error_code() );
		$this->assertCount( 0, $this->requests );
		$this->assertIsArray( Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'local_upscale', array( 'factor' => 2 ) ) );
		$this->assertIsArray( Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'convert', array( 'format' => 'jpeg' ) ) );
	}

	public function test_approve_replace_and_revert_featured_gallery_and_variation() {
		$p   = $this->product();
		$pid = $p['product']->get_id();

		$r   = Operations::run( $p['featured'], $pid, 'featured', 'square' )[0];
		$ok  = Results::approve( $r['id'], 'replace' );
		$this->assertSame( 'approved', $ok['status'] );
		$this->assertSame( $r['result_id'], (int) wc_get_product( $pid )->get_image_id() );
		$this->assertSame( '', get_post_meta( $r['result_id'], Results::DRAFT, true ), 'Approved results are visible in the library' );
		$this->assertSame( 'attachment', get_post_type( $p['featured'] ), 'Original stays in the library' );
		$this->assertSame( 409, Results::approve( $r['id'] )->get_error_data()['status'], 'Cannot approve twice' );
		$this->assertSame( 'reverted', Results::revert( $r['id'] )['status'] );
		$this->assertSame( $p['featured'], (int) wc_get_product( $pid )->get_image_id(), 'Revert restores the original' );

		$g = Operations::run( $p['gallery'][1], $pid, 'gallery', 'square' )[0];
		Results::approve( $g['id'], 'replace' );
		$this->assertSame( array( $p['gallery'][0], $g['result_id'] ), array_map( 'intval', wc_get_product( $pid )->get_gallery_image_ids() ), 'Same gallery position' );

		$var = new WC_Product_Variable();
		$var->set_name( 'Tee' );
		$var->save();
		$v = new WC_Product_Variation();
		$v->set_parent_id( $var->get_id() );
		$vimg = $this->attachment( $this->png(), $var->get_id() );
		$v->set_image_id( $vimg );
		$v->save();
		$vr = Operations::run( $vimg, $var->get_id(), 'variation:' . $v->get_id(), 'flatten' )[0];
		Results::approve( $vr['id'], 'replace' );
		$this->assertSame( $vr['result_id'], (int) wc_get_product( $v->get_id() )->get_image_id() );
	}

	public function test_add_to_gallery_reject_and_stale_replace() {
		$p   = $this->product();
		$pid = $p['product']->get_id();
		$a   = Operations::run( $p['featured'], $pid, 'featured', 'square' )[0];
		Results::approve( $a['id'], 'add' );
		$this->assertContains( $a['result_id'], array_map( 'intval', wc_get_product( $pid )->get_gallery_image_ids() ) );
		$this->assertSame( $p['featured'], (int) wc_get_product( $pid )->get_image_id() );
		Results::revert( $a['id'] );
		$this->assertNotContains( $a['result_id'], array_map( 'intval', wc_get_product( $pid )->get_gallery_image_ids() ) );

		$b = Operations::run( $p['featured'], $pid, 'featured', 'square' )[0];
		$this->assertSame( 'rejected', Results::reject( $b['id'] )['status'] );
		$this->assertNull( get_post( $b['result_id'] ), 'Rejected drafts are deleted' );

		Settings_Store::update( array( 'keep_rejected' => true ) );
		$c = Operations::run( $p['featured'], $pid, 'featured', 'square' )[0];
		Results::reject( $c['id'] );
		$this->assertNotNull( get_post( $c['result_id'] ) );

		$d = Operations::run( $p['featured'], $pid, 'featured', 'square' )[0];
		$p['product']->set_image_id( 0 );
		$p['product']->save();
		$err = Results::approve( $d['id'], 'replace' );
		$this->assertSame( 'dev3is_not_in_product', $err->get_error_code() );
		$this->assertSame( 'approved', Results::approve( $d['id'], 'library' )['status'] );
	}
}
