INSERT INTO `site` (`setting`, `value`) VALUES
('amazonsleep', '1000'),
('maxaddprocessed', '25'),
('maxnfoprocessed', '100'),
('maxrageprocessed', '75'),
('maximdbprocessed', '100'),
('maxanidbprocessed', '100'),
('maxmusicprocessed', '150'),
('maxgamesprocessed', '150'),
('maxbooksprocessed', '300'),
('maxnzbsprocessed', '1000'),
('maxpartrepair', '15000'),
('partrepair', '1'),
('binarythreads', '1'),
('postthreads', '1'),
('releasethreads', '1'),
('nzbthreads', '1'),
('maxsizetopostprocess', '100'),
('minsizetopostprocess', '1'),
('postthreadsamazon', '1'),
('postthreadsnon', '1'),
('segmentstodownload', '2'),
('passchkattempts', '1'),
('maxpartsprocessed', '3'),
('trakttvkey', ''),
('fanarttvkey', ''),
('lookuppar2', '1'),
('addpar2', '1'),
('fixnamethreads', '1'),
('fixnamesperrun', '1'),
('zippath', ''),
('processjpg', '1'),
('scrape', '1'),
('nntpretries', '10'),
('imdburl', '0'),
('yydecoderpath', ''),
('ffmpeg_duration', '5'),
('ffmpeg_image_time', '5'),
('processvideos', '0'),
('maxsizetoprocessnfo', '100'),
('minsizetoprocessnfo', '1'),
('nfothreads', '1'),
('extractusingrarinfo', '0'),
('maxnestedlevels', '3'),
('innerfileblacklist', ''),
  ('miscotherretentionhours',	'0'),
  ('mischashedretentionhours',	'0');

UPDATE `tmux` SET `value` = '78' WHERE `setting` = 'sqlpatch';