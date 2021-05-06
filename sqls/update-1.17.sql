CREATE TABLE `<<__prefix__>>raid_event_ranking` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `playerId` BIGINT NOT NULL DEFAULT '0',
  `eventId` BIGINT NOT NULL DEFAULT '0',
  `damage` INT NOT NULL DEFAULT '0',
  `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updatedAt` DATETIME on update CURRENT_TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), INDEX (`playerId`), INDEX (`eventId`)
) ENGINE = InnoDB;

ALTER TABLE `<<__prefix__>>raid_event` ADD `rewarded` tinyint(1) NOT NULL DEFAULT '0' AFTER `endTime`;