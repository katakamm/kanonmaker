-- Kratší popisky značek. Nejdelší ("česká poezie 2. pol. 20. st. a novější",
-- 38 znaků) se na mobilu nevešel na řádek. "století" se zkracuje na "stol.".
UPDATE tag SET label = 'do 18. stol.'         WHERE tag_group = 'obdobi'    AND code = 'do18';
UPDATE tag SET label = 'preromant.–19. stol.' WHERE tag_group = 'obdobi'    AND code = '19st';
UPDATE tag SET label = '20.–21. stol.'        WHERE tag_group = 'obdobi'    AND code = '20_21st';
UPDATE tag SET label = 'klasicismus a osvíc.' WHERE tag_group = 'podobdobi' AND code = 'klasicismus';
UPDATE tag SET label = 'čes. poezie po 1950'  WHERE tag_group = 'special'   AND code = 'ceska_poezie_po_1950';
