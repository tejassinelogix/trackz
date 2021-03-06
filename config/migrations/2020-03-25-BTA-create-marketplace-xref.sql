USE `Tracksz`;

CREATE TABLE `marketplace` (
  `Id` int(11) NOT NULL,
  `MarketName` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `EmailAddress` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `SellerID` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Password` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `FtpUserId` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `FtpPassword` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `PrependVenue` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `AppendVenue` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `IncreaseMinMarket` double(3,2) DEFAULT NULL,
  `FileFormat` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `FtpAppendVenue` int(11) DEFAULT NULL,
  `SuspendExport` tinyint(1) DEFAULT NULL,
  `SendDeletes` tinyint(1) DEFAULT NULL,
  `MarketAcceptPrice` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `MarketAcceptPriceVal` double(5,2) DEFAULT NULL,
  `MarketAcceptPriceValMulti` double(5,2) DEFAULT NULL,
  `MarketSpecificPrice` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `MarketAcceptPriceVal2` double(5,2) DEFAULT NULL,
  `MarketAcceptPriceValMulti2` double(5,2) DEFAULT NULL,
  `Updated` datetime DEFAULT CURRENT_TIMESTAMP,
  `Created` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `marketplace` ADD `FtpAddress` VARCHAR(150) NULL DEFAULT NULL AFTER `Password`;
ALTER TABLE `marketplace` ADD `Status` TINYINT(1) NULL DEFAULT '0' AFTER `MarketAcceptPriceValMulti2`;
ALTER TABLE `marketplace` ADD `UserId` INT(11) NULL AFTER `Status`;
