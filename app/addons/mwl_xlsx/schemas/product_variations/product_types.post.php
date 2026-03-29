<?php

defined('BOOTSTRAP') or die('Access denied');

use Tygh\Addons\ProductVariations\Product\Type\Type;

/**
 * @var array $schema
 *
 * Add updated_timestamp to variation-owned fields so that variation sync
 * does not overwrite it from the parent product.
 *
 * Without this fix, newly imported products that join an existing variation
 * group get their updated_timestamp replaced by the parent's (stale) value,
 * causing publish_down_missing_products_outdated to disable them immediately.
 */
if (isset($schema[Type::PRODUCT_TYPE_VARIATION]['fields'])) {
    $schema[Type::PRODUCT_TYPE_VARIATION]['fields'][] = 'updated_timestamp';
}

return $schema;
