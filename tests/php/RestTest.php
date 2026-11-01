<?php
/**
 * REST permissions, alt text, batches (core Jobs), recipes and the compare shortcode.
 *
 * @package Dev3\AiImageStudio
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiImageStudio\Core3\Core\Jobs;
use Dev3\AiImageStudio\Frontend\Compare;
use Dev3\AiImageStudio\Studio\Alt_Text;
use Dev3\AiImageStudio\Studio\Batch;
use Dev3\AiImageStudio\Studio\Recipes;
use Dev3\AiImageStudio\Studio\Results;

class RestTest extends Dev3is_Base_Case {

	public function test_routes_need_nonce_and_studio_capability() {
		$routes = array( array( 'GET', 'bootstrap' ), array( 'GET', 'images' ), array( 'POST', 'process' ), array( 'POST', 'alt/suggest' ), array( 'POST', 'alt/apply' ), array( 'GET', 'results' ), array( 'POST', 'results/1/approve' ), array( 'POST', 'results/bulk' ), array( 'POST', 'batch' ), array( 'GET', 'batches' ), array( 'POST', 'recipes' ), array( 'POST', 'scenes/ideas' ) );
		wp_set_current_user( 0 );
		foreach ( $routes as $r ) {
			$this->assertContains( $this->call( $r[0], $r[1] )->get_status(), array( 401, 403 ), "anon {$r[1]}" );
		}
		foreach ( array( 'subscriber', 'author' ) as $role ) {
			$this->login( $role );
			foreach ( $routes as $r ) {
				$this->assertContains( $this->call( $r[0], $r[1] )->get_status(), array( 401, 403 ), "{$role} {$r[1]}" );
			}
		}
		$this->login( 'shop_manager' );
		$this->assertSame( 200, $this->call( 'GET', 'bootstrap' )->get_status(), 'Shop managers use the studio' );
		$this->assertFalse( $this->call( 'GET', 'bootstrap' )->get_data()['canManage'] );
		$this->assertSame( 403, $this->call( 'GET', 'settings' )->get_status(), 'but not the settings' );
		$this->login();
		$this->assertSame( 403, $this->call( 'GET', 'bootstrap', array(), false )->get_status(), 'Nonce required' );
		$data = $this->call( 'GET', 'bootstrap' )->get_data();
		$this->assertTrue( $data['gd'] );
		$this->assertArrayNotHasKey( 'api_key', $data['settings'] );
		$this->assertCount( 5, $data['recipes']['recipes'] );
		$this->assertCount( 8, $data['recipes']['scenes'] );
	}

	public function test_process_list_and_decide_over_rest() {
		$this->login( 'shop_manager' );
		$p   = $this->product();
		$pid = $p['product']->get_id();
		$res = $this->call(
			'POST',
			'process',
			array(
				'attachment_id' => $p['featured'],
				'product_id'    => $pid,
				'slot'          => 'featured',
				'recipe'        => 'square-pad',
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$result = $res->get_data()['results'][0];
		$this->assertSame( 'square', $result['operation'] );
		$this->assertSame( 409, $this->call( 'POST', 'process', array( 'attachment_id' => $result['result_id'], 'operation' => 'square' ) )->get_status(), 'Drafts cannot be edited further' );

		$list = $this->call( 'GET', 'results' )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 1, $list['counts']['pending'] );
		$img = $this->call( 'GET', 'images/' . $p['featured'], array( 'product_id' => $pid ) )->get_data();
		$this->assertSame( 'featured', $img['slot'] );
		$this->assertCount( 1, $img['history'] );

		$ok = $this->call( 'POST', 'results/' . $result['id'] . '/approve', array( 'mode' => 'replace' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( $result['result_id'], (int) wc_get_product( $pid )->get_image_id() );
		$this->assertSame( 'reverted', $this->call( 'POST', 'results/' . $result['id'] . '/revert' )->get_data()['status'] );

		$a = Dev3\AiImageStudio\Studio\Operations::run( $p['gallery'][0], $pid, 'gallery', 'square' )[0];
		$b = Dev3\AiImageStudio\Studio\Operations::run( $p['gallery'][1], $pid, 'gallery', 'square' )[0];
		$bulk = $this->call(
			'POST',
			'results/bulk',
			array(
				'ids'    => array( $a['id'], $b['id'] ),
				'action' => 'reject',
			)
		)->get_data();
		$this->assertSame( 2, $bulk['done'] );
		$this->assertSame( 0, $bulk['counts']['pending'] );
	}

	public function test_alt_suggest_is_a_draft_and_apply_saves_with_history() {
		$this->login();
		$p               = $this->product();
		$this->responses = array(
			$this->json_reply(
				array(
					'alt'     => 'Blue ceramic mug with a rounded handle on a grey background',
					'title'   => 'Blue ceramic mug',
					'caption' => 'Our everyday blue mug.',
				)
			),
		);
		$res = $this->call(
			'POST',
			'alt/suggest',
			array(
				'attachment_id' => $p['featured'],
				'product_id'    => $p['product']->get_id(),
			)
		)->get_data();
		$this->assertSame( 'Blue ceramic mug', $res['draft']['title'] );
		$this->assertSame( '', get_post_meta( $p['featured'], '_wp_attachment_image_alt', true ), 'Nothing saved yet' );
		$parts = $this->requests[0]['body']['contents'][0]['parts'];
		$this->assertArrayHasKey( 'inline_data', $parts[0], 'Vision input' );
		$this->assertSame( 125, $this->requests[0]['body']['generationConfig']['responseSchema']['properties']['alt']['maxLength'] );

		$this->call(
			'POST',
			'alt/apply',
			array(
				'attachment_id' => $p['featured'],
				'product_id'    => $p['product']->get_id(),
				'source'        => 'ai',
			) + $res['draft']
		);
		$this->assertSame( $res['draft']['alt'], get_post_meta( $p['featured'], '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Blue ceramic mug', get_the_title( $p['featured'] ) );
		$history = Results::query(
			array(
				'source_id' => $p['featured'],
				'status'    => 'approved',
			)
		)['rows'];
		$this->assertSame( 'alt', $history[0]['kind'] );
		Results::revert( $history[0]['id'] );
		$this->assertSame( '', get_post_meta( $p['featured'], '_wp_attachment_image_alt', true ), 'Alt revert restores the old text' );
	}

	public function test_alt_template_preview_and_apply() {
		$this->login();
		$p   = $this->product( 'Green Bowl' );
		$cat = wp_insert_term( 'Kitchen', 'product_cat' );
		wp_set_object_terms( $p['product']->get_id(), array( (int) $cat['term_id'] ), 'product_cat' );
		$this->assertSame( 'Green Bowl - Kitchen #2', Alt_Text::template( '{product} - {category} #{n}', $p['product']->get_id(), 2 ) );
		$this->assertSame( 'Green Bowl', Alt_Text::template( '{product} - {attribute:pa_color}', $p['product']->get_id() ), 'Empty placeholders are tidied' );
		$keys = array( $p['featured'] . ':' . $p['product']->get_id() . ':featured', $p['gallery'][0] . ':' . $p['product']->get_id() . ':gallery' );
		$prev = $this->call(
			'POST',
			'alt/template',
			array(
				'targets'  => $keys,
				'template' => '{product} photo {n}',
			)
		)->get_data();
		$this->assertSame( 'Green Bowl photo 2', $prev['rows'][1]['alt'] );
		$this->assertSame( '', get_post_meta( $p['gallery'][0], '_wp_attachment_image_alt', true ), 'Preview saves nothing' );
		$this->call(
			'POST',
			'alt/template',
			array(
				'targets'  => $keys,
				'template' => '{product} photo {n}',
				'apply'    => true,
			)
		);
		$this->assertSame( 'Green Bowl photo 1', get_post_meta( $p['featured'], '_wp_attachment_image_alt', true ) );
	}

	public function test_batches_run_on_core_jobs_and_fill_the_review_queue() {
		$this->login( 'shop_manager' );
		$a   = $this->product( 'Mug A' );
		$b   = $this->product( 'Mug B' );
		$dry = $this->call(
			'POST',
			'batch',
			array(
				'product_ids' => array( $a['product']->get_id(), $b['product']->get_id() ),
				'scope'       => 'all',
				'operation'   => 'scene',
				'options'     => array( 'variants' => 2 ),
				'dry_run'     => true,
			)
		)->get_data();
		$this->assertSame( 6, $dry['count'] );
		$this->assertSame( 12, $dry['ai_calls'] );

		$this->responses = array( $this->image_reply( $this->png( 500, 500, array( 255, 255, 255 ) ) ) );
		$job             = $this->call(
			'POST',
			'batch',
			array(
				'product_ids' => array( $a['product']->get_id(), $b['product']->get_id() ),
				'scope'       => 'featured',
				'recipe'      => 'marketplace-white',
			)
		);
		$this->assertSame( 201, $job->get_status() );
		$id = $job->get_data()['id'];
		$this->assertSame( 2, $job->get_data()['total'] );
		$done = Jobs::run( $id, 60 );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( 2, $done['done'] );
		$rows = Results::query( array( 'job_id' => $id ) )['rows'];
		$this->assertCount( 2, $rows );
		$this->assertSame( 2000, $rows[0]['after']['width'], 'Recipe options applied' );
		$this->assertSame( 'Marketplace white 1:1', Batch::recent()[0]['label'] );

		// Alt-text batch with AI: each item becomes a pending alt result.
		$this->responses = array( $this->json_reply( array( 'alt' => 'A mug', 'title' => 'Mug', 'caption' => 'Mug.' ) ) );
		$alt             = Batch::create( Batch::resolve( array( 'product_ids' => array( $a['product']->get_id() ), 'scope' => 'gallery' ) ), 'alt' );
		Jobs::run( $alt, 60 );
		$pending = Results::query( array( 'job_id' => $alt ) )['rows'];
		$this->assertCount( 2, $pending );
		$this->assertSame( 'A mug', $pending[0]['alt']['alt'] );
		$this->assertSame( '', get_post_meta( $a['gallery'][0], '_wp_attachment_image_alt', true ), 'Batch alt text waits for approval' );
		Results::approve( $pending[0]['id'], '', array( 'alt' => 'Edited mug' ) );
		$this->assertContains( get_post_meta( $a['gallery'][0], '_wp_attachment_image_alt', true ) . get_post_meta( $a['gallery'][1], '_wp_attachment_image_alt', true ), array( 'Edited mug' ) );

		// Audit batch (no AI) and validation.
		$audit = Batch::create( Batch::resolve( array( 'all' => true, 'scope' => 'all' ) ), 'audit' );
		$this->assertSame( 'done', Jobs::run( $audit, 60 )['status'] );
		$this->assertWPError( Batch::create( array(), 'square' ) );
		$this->assertWPError( Batch::create( array( '1:1:featured' ), 'explode' ) );
		$this->assertSame( 400, $this->call( 'POST', 'batch', array( 'operation' => 'square' ) )->get_status(), 'No targets' );
	}

	public function test_batch_items_skip_drafts_and_parse_keys() {
		$p     = $this->product();
		$draft = Dev3\AiImageStudio\Studio\Operations::run( $p['featured'], $p['product']->get_id(), 'featured', 'square' )[0];
		$keys  = Batch::resolve( array( 'targets' => array( $draft['result_id'] . ':' . $p['product']->get_id() . ':featured', $p['featured'] . ':' . $p['product']->get_id() . ':featured', 'garbage' ) ) );
		$this->assertSame( array( $p['featured'] . ':' . $p['product']->get_id() . ':featured' ), $keys );
		$this->assertNull( Batch::parse( '1:2:hacker' ) );
		$this->assertSame( 'variation:9', Batch::parse( '1:2:variation:9' )['slot'] );
	}

	public function test_recipes_validate_and_reset() {
		$this->login();
		$bad = $this->call( 'POST', 'recipes', array( 'recipes' => array( array( 'name' => 'X', 'operation' => 'explode' ) ) ) );
		$this->assertSame( 400, $bad->get_status() );
		$ok = $this->call(
			'POST',
			'recipes',
			array(
				'recipes' => array(
					array(
						'name'      => 'Grey square',
						'operation' => 'square',
						'options'   => array(
							'color' => '#cccccc',
							'fill'  => 999,
						),
					),
				),
			)
		)->get_data();
		$this->assertSame( 'grey-square', $ok['recipes'][0]['id'] );
		$this->assertSame( 100, $ok['recipes'][0]['options']['fill'], 'Options are clamped' );
		$this->assertCount( 8, $ok['scenes'], 'Scenes untouched' );
		$this->assertSame( 'grey-square', Recipes::recipe( 'grey-square' )['id'] );
		$this->assertCount( 5, $this->call( 'POST', 'recipes/reset' )->get_data()['recipes'] );
	}

	public function test_scene_ideas() {
		$this->login();
		$p               = $this->product();
		$this->responses = array(
			$this->json_reply(
				array(
					'ideas' => array(
						array( 'label' => 'Breakfast', 'prompt' => 'on a breakfast table' ),
						array( 'label' => '', 'prompt' => 'dropped' ),
					),
				)
			),
		);
		$res = $this->call( 'POST', 'scenes/ideas', array( 'product_id' => $p['product']->get_id() ) )->get_data();
		$this->assertSame( array( array( 'label' => 'Breakfast', 'prompt' => 'on a breakfast table' ) ), $res['ideas'] );
		$this->assertSame( 400, $this->call( 'POST', 'scenes/ideas', array( 'product_id' => 0 ) )->get_status() );
	}

	public function test_compare_shortcode_markup() {
		$before = $this->attachment( $this->png() );
		$after  = $this->attachment( $this->png( 400, 300, array( 255, 255, 255 ) ) );
		$html   = do_shortcode( '[dev3is_compare before="' . $before . '" after="' . $after . '" position="30" after_label="Studio" caption="Same mug"]' );
		$this->assertStringContainsString( '--dev3is-pos:30%', $html );
		$this->assertStringContainsString( 'type="range"', $html );
		$this->assertStringContainsString( 'Drag to compare Before and Studio', $html );
		$this->assertStringContainsString( '<figcaption class="dev3is-compare__caption">Same mug</figcaption>', $html );
		$this->assertTrue( wp_script_is( 'dev3is-compare', 'enqueued' ) || ! is_readable( DEV3IS_PATH . 'build/compare.asset.php' ) );
		wp_set_current_user( 0 );
		$this->assertSame( '', Compare::render( array( 'before' => 0, 'after' => $after, 'position' => 50, 'before_label' => '', 'after_label' => '', 'caption' => '' ) ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( Compare::BLOCK ) );
	}
}
