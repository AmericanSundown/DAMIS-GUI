-- File for templorary updates

-- Updates after 2014-04-14  Version 0.1.1

-- Filter data component update
UPDATE `damis`.`component` SET `ComponentDescription` = 'Filter data description' WHERE `component`.`ComponentID` = 9; 
UPDATE `damis`.`component` SET `ComponentDescription` = 'Filter data description' WHERE `component`.`ComponentID` = 10;

--  NEW experiment statuses
INSERT INTO `damis`.`experimentstatus` (`ExperimentStatus`, `ExperimentStatusID`) VALUES ('SUSPENDED', 5), ('EXAMPLE', 6); 

-- DF component was with bad parameter. q set to r
UPDATE `damis`.`parameter` SET `ParameterSlug` = 'r' WHERE `parameter`.`ParameterID` = 169;
UPDATE `damis`.`parameter` SET `ParameterSlug` = 'r' WHERE `parameter`.`ParameterID` = 172; 
