-- Jeu de données déterministe : aucune fonction aléatoire, tout dérive de n.
CREATE TEMPORARY TABLE seq (n INT UNSIGNED NOT NULL PRIMARY KEY);
INSERT INTO seq (n)
SELECT a.d + b.d * 10 + c.d * 100 + e.d * 1000 + 1
FROM (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
     (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b,
     (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c,
     (SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) e;

-- 20 catégories : 5 racines, puis 15 sous-catégories
INSERT INTO categories (id, parent_id, name)
SELECT n, NULL, ELT(n, 'Maison', 'Jardin', 'Sport', 'Livres', 'Jeux') FROM seq WHERE n <= 5;
INSERT INTO categories (id, parent_id, name)
SELECT n, ((n - 1) % 5) + 1, CONCAT('Rayon ', n) FROM seq WHERE n BETWEEN 6 AND 20;

-- 1 000 clients
INSERT INTO customers (id, email, first_name, last_name, country, created_at)
SELECT n,
       CONCAT('client', n, '@example.test'),
       ELT(n % 8 + 1, 'Alice', 'Bruno', 'Chloé', 'David', 'Emma', 'Farid', 'Gaëlle', 'Hugo'),
       ELT(n % 6 + 1, 'Martin', 'Bernard', 'Dubois', 'Moreau', 'Laurent', 'Simon'),
       ELT(n % 5 + 1, 'FR', 'DE', 'ES', 'IT', 'BE'),
       DATE_ADD('2024-01-01 00:00:00', INTERVAL n * 7 HOUR)
FROM seq WHERE n <= 1000;

-- 400 produits
INSERT INTO products (id, category_id, sku, name, price_cents, stock)
SELECT n, n % 20 + 1, CONCAT('SKU-', LPAD(n, 5, '0')), CONCAT('Produit ', n),
       500 + (n * 37) % 20000, (n * 11) % 250
FROM seq WHERE n <= 400;

-- 3 000 commandes
INSERT INTO orders (id, customer_id, status, ordered_at)
SELECT n, (n * 7) % 1000 + 1,
       ELT(n % 4 + 1, 'pending', 'paid', 'shipped', 'cancelled'),
       DATE_ADD('2025-01-01 08:00:00', INTERVAL n * 53 MINUTE)
FROM seq WHERE n <= 3000;

-- 7 000 lignes de commande (jusqu'à 3 lignes par commande)
INSERT INTO order_items (id, order_id, line_no, product_id, quantity, unit_price_cents)
SELECT s.n, (s.n - 1) % 3000 + 1, (s.n - 1) DIV 3000 + 1, p.id, s.n % 5 + 1, p.price_cents
FROM seq s JOIN products p ON p.id = (s.n * 13) % 400 + 1
WHERE s.n <= 7000;

DROP TEMPORARY TABLE seq;
