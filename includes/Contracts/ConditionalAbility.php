<?php

declare(strict_types=1);

namespace GalatanOvidiu\AbilitiesCatalogWoo\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An {@see Ability} that is only available under a runtime condition.
 *
 * Most abilities wrap WordPress core and are always available, so they implement
 * {@see Ability} directly. An ability that wraps an optional dependency — a
 * third-party plugin that may be inactive — implements this interface instead, so
 * the {@see \GalatanOvidiu\AbilitiesCatalogWoo\Registry} registers it ONLY when the
 * dependency is present. When {@see isAvailable()} returns false the ability does
 * not register at all: it is absent from the Abilities API, the dangerous-tools
 * map, and the screen-link map, rather than registering and then denying every
 * call. The capability check stays the hard guard on whatever does register.
 *
 * {@see isAvailable()} is evaluated at registration time (on
 * `wp_abilities_api_init` and when the consumer filters run), never at file load
 * or in the constructor, so the dependency's plugin has already loaded by the time
 * it is asked.
 *
 * @since 0.3.0
 */
interface ConditionalAbility extends Ability {

	/**
	 * Whether this ability's runtime dependency is present, so it may register.
	 *
	 * Must not touch the dependency's symbols beyond detecting them (e.g.
	 * `function_exists()` / `class_exists()`), so it is safe to call whether or
	 * not the dependency is active.
	 *
	 * @return bool True when the dependency is available and the ability should register.
	 */
	public function isAvailable(): bool;
}
