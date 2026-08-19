-- Kapitola se nově zobrazuje jako šestá značka u každého díla.
--
-- Na to jsou plné názvy (29-61 znaků) příliš dlouhé - u 461 děl by značka
-- přebila samotný název knihy. Proto ještě jeden, opravdu krátký tvar jen
-- pro značku; `name` zůstává doslovný nadpis z dokumentu (podle něj import
-- kapitoly poznává) a `short_name` delší popisný tvar pro filtr a nadpisy.
ALTER TABLE chapter ADD COLUMN chip_name VARCHAR(64) NULL AFTER short_name;

-- Předpona "kap." je tu schválně: bez ní by u díla stály vedle sebe dvě
-- značky se stejným textem (kapitola "do 18. stol." a období "do 18. stol.")
-- a vypadalo by to jako chyba.
UPDATE chapter SET chip_name = 'kap. do 18. stol.'   WHERE sort_order = 1;
UPDATE chapter SET chip_name = 'kap. světová 19.'    WHERE sort_order = 2;
UPDATE chapter SET chip_name = 'kap. česká 19.'      WHERE sort_order = 3;
UPDATE chapter SET chip_name = 'kap. drama 20.–21.'  WHERE sort_order = 4;
UPDATE chapter SET chip_name = 'kap. poezie 20.–21.' WHERE sort_order = 5;
UPDATE chapter SET chip_name = 'kap. světová próza'  WHERE sort_order = 6;
UPDATE chapter SET chip_name = 'kap. česká próza'    WHERE sort_order = 7;
