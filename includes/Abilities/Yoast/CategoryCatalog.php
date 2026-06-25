<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Abilities\Yoast;

use GalatanOvidiu\AbilitiesCatalogYoast\Contracts\CategoryProvider;
use GalatanOvidiu\AbilitiesCatalogYoast\Support\YoastPlugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Category catalog for the Yoast SEO ability group.
 *
 * The {@see \GalatanOvidiu\AbilitiesCatalogYoast\Registry} discovers this provider
 * alongside the abilities and registers its categories on
 * `wp_abilities_api_categories_init`. Every Yoast ability references the `og-yoast`
 * category through `args()['category']`.
 *
 * The group's abilities only register when Yoast SEO is active (they are
 * {@see \GalatanOvidiu\AbilitiesCatalogYoast\Contracts\ConditionalAbility}s), so the
 * category gates on the same condition — when Yoast is off there are no abilities to
 * categorize and the add-on leaves no Yoast footprint. The check is safe here because
 * categories register after plugins have loaded, never at file load.
 *
 * The `og-yoast` slug is a machine identifier only; it clears Yoast's own reserved
 * `yoast-seo` category slug. The human label and description stay clean Yoast SEO
 * wording.
 *
 * @since 0.1.0
 */
final class CategoryCatalog implements CategoryProvider {

	/**
	 * {@inheritDoc}
	 */
	public function categories(): array {
		if ( ! YoastPlugin::isActive() ) {
			return array();
		}

		return array(
			'og-yoast' => array(
				'slug'        => 'og-yoast',
				'label'       => __( 'Yoast SEO', 'abilities-catalog-yoast' ),
				'description' => __( 'Abilities that read and write Yoast SEO metadata for posts, terms, and authors, manage the site SEO settings, and rebuild the SEO index.', 'abilities-catalog-yoast' ),
			),
		);
	}
}
