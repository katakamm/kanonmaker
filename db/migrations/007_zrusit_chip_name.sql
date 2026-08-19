-- Kapitola se u díla jako značka nezobrazuje, jen ve filtru, kde se používá
-- popisný `short_name`. Krátký tvar pro značku tedy nemá co obsluhovat.
ALTER TABLE chapter DROP COLUMN chip_name;
