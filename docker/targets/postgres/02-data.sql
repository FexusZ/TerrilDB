-- Jeu de données déterministe : aucune fonction aléatoire, tout dérive de n.

-- 20 catégories : 5 racines, puis 15 sous-catégories
INSERT INTO categories (id, parent_id, name)
SELECT n, NULL, (ARRAY['Maison', 'Jardin', 'Sport', 'Livres', 'Jeux'])[n] FROM generate_series(1, 5) AS n;
INSERT INTO categories (id, parent_id, name)
SELECT n, (n - 1) % 5 + 1, 'Rayon ' || n FROM generate_series(6, 20) AS n;

-- 1 000 clients
INSERT INTO customers (id, email, first_name, last_name, country, created_at)
SELECT n,
       'client' || n || '@example.test',
       (ARRAY['Alice', 'Bruno', 'Chloé', 'David', 'Emma', 'Farid', 'Gaëlle', 'Hugo'])[n % 8 + 1],
       (ARRAY['Martin', 'Bernard', 'Dubois', 'Moreau', 'Laurent', 'Simon'])[n % 6 + 1],
       (ARRAY['FR', 'DE', 'ES', 'IT', 'BE'])[n % 5 + 1],
       timestamp '2024-01-01 00:00:00' + n * interval '7 hours'
FROM generate_series(1, 1000) AS n;

-- 400 produits
INSERT INTO products (id, category_id, sku, name, price_cents, stock)
SELECT n, n % 20 + 1, 'SKU-' || lpad(n::text, 5, '0'), 'Produit ' || n,
       500 + (n * 37) % 20000, (n * 11) % 250
FROM generate_series(1, 400) AS n;

-- 3 000 commandes
INSERT INTO orders (id, customer_id, status, ordered_at)
SELECT n, (n * 7) % 1000 + 1,
       (ARRAY['pending', 'paid', 'shipped', 'cancelled'])[n % 4 + 1],
       timestamp '2025-01-01 08:00:00' + n * interval '53 minutes'
FROM generate_series(1, 3000) AS n;

-- 7 000 lignes de commande (jusqu'à 3 lignes par commande)
INSERT INTO order_items (id, order_id, line_no, product_id, quantity, unit_price_cents)
SELECT s.n, (s.n - 1) % 3000 + 1, (s.n - 1) / 3000 + 1, p.id, s.n % 5 + 1, p.price_cents
FROM generate_series(1, 7000) AS s(n) JOIN products p ON p.id = (s.n * 13) % 400 + 1;

-- Les identités ont reçu des id explicites : recaler les séquences
SELECT setval(pg_get_serial_sequence('categories', 'id'), (SELECT max(id) FROM categories));
SELECT setval(pg_get_serial_sequence('customers', 'id'), (SELECT max(id) FROM customers));
SELECT setval(pg_get_serial_sequence('products', 'id'), (SELECT max(id) FROM products));
SELECT setval(pg_get_serial_sequence('orders', 'id'), (SELECT max(id) FROM orders));
SELECT setval(pg_get_serial_sequence('order_items', 'id'), (SELECT max(id) FROM order_items));
