
ALTER TABLE `releases` DELETE COLUMN `nzb_guid`;
ALTER TABLE `releases` DROP INDEX `ix_releases_nzb_guid`;

UPDATE `tmux` set `value` = '31' where `setting` = 'sqlpatch';