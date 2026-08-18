INSERT INTO school (name) VALUES ('Gymnázium Jana Keplera');

INSERT INTO canon (school_id, school_year)
SELECT id, '2025/2026' FROM school WHERE name = 'Gymnázium Jana Keplera';

INSERT INTO tag (canon_id, tag_group, code, label, sort_order)
SELECT c.id, t.tag_group, t.code, t.label, t.sort_order
FROM canon c
CROSS JOIN (
    SELECT 'obdobi'    AS tag_group, 'do18'       AS code, 'do konce 18. století'      AS label, 1 AS sort_order UNION ALL
    SELECT 'obdobi',    '19st',       'preromantismus–19. století',                 2 UNION ALL
    SELECT 'obdobi',    '20_21st',    '20. a 21. století',                          3 UNION ALL
    SELECT 'podobdobi', 'starovek',   'starověk',                                   1 UNION ALL
    SELECT 'podobdobi', 'stredovek',  'středověk',                                  2 UNION ALL
    SELECT 'podobdobi', 'renesance',  'renesance',                                  3 UNION ALL
    SELECT 'podobdobi', 'baroko',     'baroko',                                     4 UNION ALL
    SELECT 'podobdobi', 'klasicismus','klasicismus a osvícenství',                  5 UNION ALL
    SELECT 'narodni',   'ceska',      'česká literatura',                           1 UNION ALL
    SELECT 'narodni',   'svetova',    'světová literatura',                         2 UNION ALL
    SELECT 'forma',     'poezie',     'poezie',                                     1 UNION ALL
    SELECT 'forma',     'proza',      'próza',                                      2 UNION ALL
    SELECT 'forma',     'drama',      'drama',                                      3 UNION ALL
    SELECT 'special',   'ceska_poezie_po_1950', 'česká poezie 2. pol. 20. st. a novější', 1
) AS t
WHERE c.school_year = '2025/2026';

INSERT INTO rule (canon_id, sort_order, type, params, label)
SELECT c.id, r.sort_order, r.type, r.params, r.label
FROM canon c
CROSS JOIN (
    SELECT 1 AS sort_order, 'min_total' AS type,
           '{"min":25}' AS params,
           'Celkem 25 titulů' AS label UNION ALL
    SELECT 2, 'min_count',
           '{"group":"obdobi","code":"do18","min":5}',
           'Literatura do konce 18. století (min. 5)' UNION ALL
    SELECT 3, 'min_distinct',
           '{"scope_group":"obdobi","scope_code":"do18","group":"podobdobi","min":3}',
           'Nejméně tři literární období do konce 18. století' UNION ALL
    SELECT 4, 'min_count',
           '{"group":"obdobi","code":"19st","min":3}',
           'Od preromantismu do konce 19. století (min. 3)' UNION ALL
    SELECT 5, 'min_count',
           '{"group":"obdobi","code":"20_21st","min":8}',
           'Literatura 20. a 21. století (min. 8)' UNION ALL
    SELECT 6, 'min_count',
           '{"group":"narodni","code":"svetova","min":8}',
           'Světová literatura (min. 8)' UNION ALL
    SELECT 7, 'min_count',
           '{"group":"narodni","code":"ceska","min":8}',
           'Česká literatura (min. 8)' UNION ALL
    SELECT 8, 'min_count',
           '{"group":"forma","code":"drama","min":3}',
           'Drama (min. 3)' UNION ALL
    SELECT 9, 'min_count',
           '{"group":"forma","code":"poezie","min":3}',
           'Poezie (min. 3)' UNION ALL
    SELECT 10, 'min_count',
           '{"group":"forma","code":"proza","min":3}',
           'Próza (min. 3)' UNION ALL
    SELECT 11, 'min_count',
           '{"group":"special","code":"ceska_poezie_po_1950","min":1}',
           'Česká poezie 2. pol. 20. století nebo 21. století (min. 1)' UNION ALL
    SELECT 12, 'max_per_author',
           '{"max":2,"distinct_form":true}',
           'Od jednoho autora nejvýše dva tituly různé literární formy'
) AS r
WHERE c.school_year = '2025/2026';
