-- Zkrácený název kapitoly pro zobrazení.
--
-- Sloupec `name` zůstává doslovným nadpisem ze školního dokumentu — podle něj
-- import kapitoly poznává a při každém běhu ho přepisuje. Kdyby se zkracoval
-- rovnou v něm, příští import by zkrácení zahodil. Proto zvlášť `short_name`,
-- kterého se import nedotýká; když je prázdný, zobrazí se `name`.
ALTER TABLE chapter ADD COLUMN short_name VARCHAR(255) NULL AFTER name;

UPDATE chapter
   SET short_name = 'Světová a česká lit. do konce 18. stol. (kromě preromantismu)'
 WHERE name LIKE 'Světová a česká literatura do konce 18.%';

-- Delší, srozumitelnější tvar — vejde se a nic nezkracuje na úkor jasnosti.
UPDATE tag SET label = 'česká poezie po roce 1950'
 WHERE tag_group = 'special' AND code = 'ceska_poezie_po_1950';
