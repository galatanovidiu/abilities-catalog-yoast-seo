<?php
/**
 * Integration test for the Registry's ConditionalAbility gate.
 *
 * @package AbilitiesCatalogYoast\Tests
 */

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogYoast\Tests\Integration;

use GalatanOvidiu\AbilitiesCatalogYoast\Registry;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\Fixtures\UnavailableConditionalAbility;
use GalatanOvidiu\AbilitiesCatalogYoast\Tests\TestCase;
use ReflectionProperty;

/**
 * A ConditionalAbility whose dependency is absent must NOT register, and must not
 * appear in the dangerous-tools or screen-link filters. This is the gate that
 * keeps an optional-dependency ability (e.g. the CF7 group) out of the catalog
 * when its plugin is inactive.
 */
final class ConditionalAbilityTest extends TestCase {

	public function test_unavailable_conditional_ability_is_not_registered(): void {
		$registry = new Registry();

		$prop = new ReflectionProperty( Registry::class, 'abilities' );
		$prop->setAccessible( true );
		$prop->setValue( $registry, array( 'catalog-test/unavailable-conditional' => new UnavailableConditionalAbility() ) );

		$registry->registerAbilities();

		$this->assertFalse(
			wp_has_ability( 'catalog-test/unavailable-conditional' ),
			'An unavailable ConditionalAbility must not be registered.'
		);
	}

	public function test_unavailable_conditional_ability_is_excluded_from_filters(): void {
		$registry = new Registry();

		$prop = new ReflectionProperty( Registry::class, 'abilities' );
		$prop->setAccessible( true );
		$prop->setValue( $registry, array( 'catalog-test/unavailable-conditional' => new UnavailableConditionalAbility() ) );

		$this->assertArrayNotHasKey(
			'catalog-test/unavailable-conditional',
			$registry->contributeDangerousTools( array() ),
			'An unavailable ConditionalAbility must not be contributed to the dangerous-tools map.'
		);
		$this->assertArrayNotHasKey(
			'catalog-test/unavailable-conditional',
			$registry->contributeScreenLinks( array() ),
			'An unavailable ConditionalAbility must not be contributed to the screen-links map.'
		);
	}
}
