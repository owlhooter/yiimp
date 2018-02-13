-- new update to allow for btc price to be stored separately.

ALTER TABLE `coins` ADD `price_btc` DOUBLE NULL DEFAULT NULL AFTER `specifications`;
