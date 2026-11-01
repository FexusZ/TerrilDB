USE shop;

-- Jeu de données déterministe : aucune fonction aléatoire, tout dérive de n.

INSERT INTO categories
SELECT number + 1,
       if(number < 5, NULL, toUInt32((number % 5) + 1)),
       if(number < 5, ['Maison', 'Jardin', 'Sport', 'Livres', 'Jeux'][number + 1], concat('Rayon ', toString(number + 1)))
FROM numbers(20);

INSERT INTO customers
SELECT n,
       concat('client', toString(n), '@example.test'),
       ['Alice', 'Bruno', 'Chloé', 'David', 'Emma', 'Farid', 'Gaëlle', 'Hugo'][n % 8 + 1],
       ['Martin', 'Bernard', 'Dubois', 'Moreau', 'Laurent', 'Simon'][n % 6 + 1],
       ['FR', 'DE', 'ES', 'IT', 'BE'][n % 5 + 1],
       toDateTime('2024-01-01 00:00:00') + n * 7 * 3600
FROM (SELECT toUInt32(number + 1) AS n FROM numbers(1000));

INSERT INTO products
SELECT n, n % 20 + 1, concat('SKU-', leftPad(toString(n), 5, '0')), concat('Produit ', toString(n)),
       500 + (n * 37) % 20000, (n * 11) % 250
FROM (SELECT toUInt32(number + 1) AS n FROM numbers(400));

INSERT INTO orders
SELECT n, (n * 7) % 1000 + 1,
       ['pending', 'paid', 'shipped', 'cancelled'][n % 4 + 1],
       toDateTime('2025-01-01 08:00:00') + n * 53 * 60
FROM (SELECT toUInt32(number + 1) AS n FROM numbers(3000));

INSERT INTO order_items
SELECT s.n, (s.n - 1) % 3000 + 1, toUInt16(intDiv(s.n - 1, 3000) + 1), p.id, toUInt16(s.n % 5 + 1), p.price_cents
FROM (SELECT toUInt32(number + 1) AS n, toUInt32((number + 1) * 13 % 400 + 1) AS product FROM numbers(7000)) AS s
JOIN products AS p ON p.id = s.product;
