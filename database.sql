CREATE DATABASE `mybuh`;
USE `mybuh`;
CREATE TABLE `queues` (`id` INT AUTO_INCREMENT, `name` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`));
INSERT INTO `queues` VALUES
(1, 'task-1'),
(2, 'task-2'),
(3, 'task-3');
CREATE TABLE `rand_numbers` (`id` INT AUTO_INCREMENT, `digit` INT NOT NULL, PRIMARY KEY (`id`));
INSERT INTO `rand_numbers` VALUES
(1, 1),
(2, 10),
(3, 5),
(4, 3),
(5, 8),
(6, 7),
(7, 9),
(8, 2),
(9, 4),
(10, 6);